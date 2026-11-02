<?php

namespace App\Support\Workouts;

use App\Domain\Model\ClosedRange;
use App\Domain\Model\JointPain;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\JointPainSwap;
use App\Domain\Progression\LoadRules;
use App\Domain\Progression\LoggedExercise;
use App\Domain\Progression\LoggedSet;
use App\Domain\Progression\PlannedLoad;
use App\Domain\Progression\RecoveryLoad;
use App\Domain\Progression\RecoveryLoadInputs;
use App\Domain\Progression\RecoveryLoadResult;
use App\Domain\Progression\SetLoad;
use App\Domain\Progression\Underperformance;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

/**
 * The recovery flags on a session's targets (PROG-018, PROG-051, PROG-053) and the swap suggestion (EX-009), from
 * rp-clone's WorkoutModel+Recovery. They are worked out from the logged sets and stored feedback when asked and never
 * stored: the session writes the targets it shows when it starts, like any others. The rules are
 * {@see RecoveryLoad} and {@see JointPainSwap}; this gathers what they read.
 */
final class SessionRecovery
{
    /**
     * @param  Collection<int, WorkoutSession>  $earlier  the mesocycle's sessions before this one, not skipped, oldest first
     * @param  Collection<int, WorkoutSet>  $sets  the sets of those sessions
     * @param  Collection<int, Exercise>  $exercises  the exercises those sets name, keyed by id
     * @param  Collection<int, ExerciseFeedback>  $lastWeekPain  last week's same-day session's joint answers, keyed by exercise id
     */
    private function __construct(
        private readonly User $user,
        private readonly WorkoutSession $session,
        private readonly WeightUnit $unit,
        private readonly Collection $earlier,
        private readonly Collection $sets,
        private readonly Collection $exercises,
        private readonly Collection $lastWeekPain,
    ) {}

    /** What the flags read for one session of the user, with weights in `$unit`, the unit the user sees. */
    public static function load(User $user, WorkoutSession $session, WeightUnit $unit): self
    {
        $earlier = $session->mesocycle_id === null ? new Collection : WorkoutSession::query()
            ->where('user_id', $user->id)->where('mesocycle_id', $session->mesocycle_id)->whereKeyNot($session->id)
            ->where('is_skipped', false)->get()
            ->filter(fn (WorkoutSession $other) => [$other->week, $other->day] < [$session->week, $session->day])
            ->sortBy(fn (WorkoutSession $other) => [$other->week, $other->day])->values();
        $sets = WorkoutSet::query()->whereIn('workout_session_id', $earlier->modelKeys())->orderBy('position')->orderBy('id')->get();
        $lastWeek = $earlier->first(fn (WorkoutSession $other) => $other->week === $session->week - 1 && $other->day === $session->day);

        return new self(
            user: $user,
            session: $session,
            unit: $unit,
            earlier: $earlier,
            sets: $sets,
            exercises: Exercise::query()->whereIn('id', $sets->pluck('exercise_id')->filter()->unique()->values())->get()->keyBy('id'),
            lastWeekPain: $lastWeek === null ? new Collection
                : ExerciseFeedback::query()->where('workout_session_id', $lastWeek->id)->get()->keyBy('exercise_id'),
        );
    }

    /**
     * PROG-020 with the recovery flags on top: the exercise's targets and why they moved, if they did. Week 1 and a
     * deload week keep the planned targets.
     *
     * @param  list<PlannedLoad>  $planned  the exercise's PROG-020 targets, in the user's unit
     * @param  ?int  $rir  null in a deload week
     */
    public function recovery(Exercise $exercise, array $planned, ?int $rir, bool $isDeload, bool $isFatLoss, LoadRules $rules): RecoveryLoadResult
    {
        $lastWeekSession = $this->earlier->first(fn (WorkoutSession $other) => $other->week === $this->session->week - 1 && $other->day === $this->session->day);
        $lastWeek = $lastWeekSession === null ? [] : $this->setsOf($lastWeekSession, $exercise->id)
            ->filter(fn (WorkoutSet $set) => $set->is_logged && ! $set->is_skipped && $set->actual_reps !== null)
            ->map(fn (WorkoutSet $set) => new SetLoad($this->weight($set->actual_weight_kg), (int) $set->actual_reps))->values()->all();
        if ($isDeload || $lastWeek === []) {
            return new RecoveryLoadResult($planned, null);
        }

        $flagged = Underperformance::flaggedMuscles($this->earlier->map(fn (WorkoutSession $other) => $this->logged($other))->values()->all());
        $pain = $this->lastWeekPain->get($exercise->id)?->joint_pain;

        return RecoveryLoad::apply(new RecoveryLoadInputs(
            planned: $planned,
            lastWeek: $lastWeek,
            rir: $rir,
            isDeload: $isDeload,
            isFatLoss: $isFatLoss,
            rules: $rules,
            muscle: $exercise->muscle_group,
            flaggedMuscle: in_array($exercise->muscle_group, $flagged, true) ? $exercise->muscle_group : null,
            history: $this->earlier->map(fn (WorkoutSession $other) => $this->loggedExercise($other, $exercise))->filter()->values()->all(),
            lastWeekJointPain: $pain === null ? null : JointPain::from($pain),
        ));
    }

    /**
     * EX-009: "{exercise} hurt 2 sessions running. Consider swapping it." while the exercise's latest two joint
     * answers (FB-002), on any day or mesocycle, are both Moderate or A lot. Answers are ordered by the start of their
     * session (actual, else planned), then week, day and row id.
     */
    public function swapSuggestion(Exercise $exercise): ?string
    {
        $rows = ExerciseFeedback::query()->where('user_id', $this->user->id)->where('exercise_id', $exercise->id)->get();
        $sessions = WorkoutSession::query()->whereIn('id', $rows->pluck('workout_session_id'))->get()->keyBy('id');
        $answers = $rows->filter(fn (ExerciseFeedback $row) => $sessions->has($row->workout_session_id))
            ->sortBy(function (ExerciseFeedback $row) use ($sessions) {
                $session = $sessions->get($row->workout_session_id);

                return [($session->actual_start_at ?? $session->planned_start_at)?->getTimestamp() ?? PHP_INT_MIN, $session->week, $session->day, $row->id];
            })
            ->map(fn (ExerciseFeedback $row) => JointPain::from($row->joint_pain))->values()->all();

        return JointPainSwap::isSuggested($answers) ? JointPainSwap::suggestion($exercise->name) : null;
    }

    /** @return Collection<int, WorkoutSet> */
    private function setsOf(WorkoutSession $session, ?int $exerciseId = null): Collection
    {
        return $this->sets->filter(fn (WorkoutSet $set) => $set->workout_session_id === $session->id
            && ($exerciseId === null || $set->exercise_id === $exerciseId))->values();
    }

    /** @return list<LoggedExercise> a session as the flags read it: its logged working sets with a rep target */
    private function logged(WorkoutSession $session): array
    {
        $ids = $this->setsOf($session)->pluck('exercise_id')->filter()->unique()->values();

        return $ids->map(fn (int $id) => $this->exercises->has($id) ? $this->loggedExercise($session, $this->exercises->get($id)) : null)
            ->filter()->values()->all();
    }

    private function loggedExercise(WorkoutSession $session, Exercise $exercise): ?LoggedExercise
    {
        $sets = $this->setsOf($session, $exercise->id)
            ->filter(fn (WorkoutSet $set) => $set->is_logged && ! $set->is_skipped && $set->target_reps !== null && $set->actual_reps !== null)
            ->map(fn (WorkoutSet $set) => new LoggedSet($this->weight($set->actual_weight_kg) ?? 0.0, (int) $set->target_reps, (int) $set->actual_reps))
            ->values()->all();

        return $sets === [] ? null : new LoggedExercise(
            $exercise->name, $exercise->muscle_group, new ClosedRange((int) $exercise->rep_range_min, (int) $exercise->rep_range_max), $sets,
        );
    }

    private function weight(?float $kg): ?float
    {
        return $kg === null || $this->unit === WeightUnit::Kg ? $kg : UnitConversion::kgToLb($kg);
    }
}
