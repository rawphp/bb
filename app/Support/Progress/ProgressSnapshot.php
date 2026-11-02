<?php

namespace App\Support\Progress;

use App\Domain\Model\HeightUnit;
use App\Domain\Model\Phase as DomainPhase;
use App\Domain\Model\StepPlanDay;
use App\Domain\Model\WeighIn as DomainWeighIn;
use App\Domain\Model\WeightUnit;
use App\Domain\Progress\LoggedWorkingSet;
use App\Domain\Progress\ProgressMesocycle;
use App\Domain\Weight\TrendWeight;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * What the Progress tab looks at, read once per invoke (ANA-001 to ANA-011): the plan, phases, mesocycles and week
 * as {@see TodaySnapshot} reads them, every weigh-in, today's steps, the logged sets of saved mesocycles with their
 * sessions, and when each exercise was last used.
 *
 * Dates are whole days at midnight UTC, read from their Y-m-d.
 */
final class ProgressSnapshot
{
    private ?TrendWeight $trend = null;

    /**
     * @param  TodaySnapshot  $week  the plan, profile, phases, mesocycles and plan weeks, and the week around today
     * @param  Collection<int, WeighIn>  $weighIns  all of them
     * @param  ?int  $steps  today's step count; null when none is stored
     * @param  Collection<int, WorkoutSession>  $sessions  those in a mesocycle
     * @param  Collection<int, WorkoutSet>  $sets  the logged sets of those sessions
     * @param  Collection<int, Exercise>  $exercises  the ones the user can see
     * @param  array<int, string>  $lastUsed  exercise id => start of the latest session with a logged set of it
     */
    public function __construct(
        public readonly TodaySnapshot $week,
        public readonly Collection $weighIns,
        public readonly ?int $steps,
        public readonly Collection $sessions,
        public readonly Collection $sets,
        public readonly Collection $exercises,
        public readonly array $lastUsed,
    ) {}

    public static function load(User $user): self
    {
        $week = TodaySnapshot::loadWeek($user);
        $steps = Step::query()->where('user_id', $user->id)->whereDate('date', $week->today()->format('Y-m-d'))->value('count');

        return new self(
            week: $week,
            weighIns: WeighIn::query()->where('user_id', $user->id)->orderBy('weighed_at')->get(['id', 'weight_kg', 'waist_cm', 'weighed_at']),
            steps: $steps === null ? null : (int) $steps,
            sessions: WorkoutSession::query()->where('user_id', $user->id)->whereNotNull('mesocycle_id')->get(['id', 'mesocycle_id', 'week']),
            sets: WorkoutSet::query()->where('user_id', $user->id)->where('is_logged', true)->whereNotNull('exercise_id')
                ->whereHas('session', fn ($sessions) => $sessions->whereNotNull('mesocycle_id'))
                ->get(['id', 'workout_session_id', 'exercise_id', 'is_logged']),
            exercises: Exercise::query()->visibleTo($user)->get(['id', 'name', 'muscle_group']),
            lastUsed: self::lastUsed($user),
        );
    }

    /**
     * EX-013: the latest start (actual, else planned) of the user's sessions that are not deleted and hold a logged
     * set of each exercise. An exercise with no such date is left out.
     *
     * @return array<int, string>
     */
    private static function lastUsed(User $user): array
    {
        $rows = WorkoutSet::query()
            ->join('workout_sessions as s', 's.id', '=', 'workout_sets.workout_session_id')
            ->where('workout_sets.user_id', $user->id)
            ->where('workout_sets.is_logged', true)
            ->whereNotNull('workout_sets.exercise_id')
            ->where('s.user_id', $user->id)
            ->whereNull('s.deleted_at')
            ->groupBy('workout_sets.exercise_id')
            ->selectRaw('workout_sets.exercise_id as exercise_id, max(coalesce(s.actual_start_at, s.planned_start_at)) as last_used')
            ->toBase()
            ->get();

        $lastUsed = [];
        foreach ($rows as $row) {
            if ($row->last_used !== null) {
                $lastUsed[(int) $row->exercise_id] = (string) $row->last_used;
            }
        }

        return $lastUsed;
    }

    public function today(): DateTimeImmutable
    {
        return $this->week->today();
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public function plan(): ?Plan
    {
        return $this->week->train->plan;
    }

    public function weightUnit(): WeightUnit
    {
        return WeightUnit::tryFrom((string) ($this->week->train->profile->weight_unit ?? '')) ?? WeightUnit::Kg;
    }

    /** The waist is shown in centimetres unless the user's height unit is feet and inches. */
    public function usesCentimetres(): bool
    {
        return ($this->week->train->profile->height_unit ?? HeightUnit::Cm->value) === HeightUnit::Cm->value;
    }

    /** @return list<StepPlanDay> */
    public function stepPlan(): array
    {
        return collect(TodaySnapshot::json($this->week->train->profile?->step_plan))
            ->map(fn (array $day) => new StepPlanDay((int) $day['weekday'], (int) $day['min'], (int) $day['max']))
            ->values()->all();
    }

    /** @return list<DomainPhase> in the order the phases are kept: by start */
    public function phases(): array
    {
        return $this->week->train->phases->map(fn (Phase $phase) => PlanSnapshot::domainPhase($phase))->values()->all();
    }

    /**
     * ANA-008: the saved mesocycles, with their dates. A draft has none and is left out. One ended early runs to the
     * day before it was ended (MESO-043).
     *
     * @return list<ProgressMesocycle>
     */
    public function mesocycles(): array
    {
        return $this->week->train->mesocycles
            ->filter(fn (Mesocycle $mesocycle) => $mesocycle->isSaved())
            ->map(fn (Mesocycle $mesocycle) => new ProgressMesocycle(
                id: (string) $mesocycle->id,
                phaseId: $mesocycle->phase_id === null ? null : (string) $mesocycle->phase_id,
                name: $mesocycle->name ?? 'Mesocycle',
                start: TrainSnapshot::day($mesocycle->start_date),
                weeks: PlanSnapshot::effectiveWeeks($mesocycle),
            ))->values()->all();
    }

    /** WT-010: the trend over all the user's weigh-ins. */
    public function trend(): TrendWeight
    {
        return $this->trend ??= new TrendWeight(
            $this->weighIns->map(fn (WeighIn $weighIn) => new DomainWeighIn(
                (string) $weighIn->id, TodaySnapshot::instant($weighIn->weighed_at), (float) $weighIn->weight_kg,
            ))->values()->all(),
            $this->zone(),
        );
    }

    /** The plan's start; without a plan, the first phase's, then the first weigh-in's day, then today. */
    public function planStart(): DateTimeImmutable
    {
        if ($this->plan() !== null) {
            return TrainSnapshot::day($this->plan()->start_date);
        }
        $phases = $this->phases();

        return $phases === [] ? ($this->trend()->points[0]->day ?? $this->today()) : min(array_map(fn (DomainPhase $phase) => $phase->start, $phases));
    }

    /**
     * ANA-007, WO-025: the logged sets of sessions that belong to a mesocycle, whose exercise still exists, with the
     * muscle of the exercise.
     *
     * @return list<LoggedWorkingSet>
     */
    public function workingSets(): array
    {
        $sessions = $this->sessions->keyBy(fn (WorkoutSession $session) => (string) $session->id);
        $muscles = $this->exercises->mapWithKeys(fn (Exercise $exercise) => [(int) $exercise->id => $exercise->muscle_group]);
        $working = [];
        foreach ($this->sets as $set) {
            $session = $sessions->get((string) $set->workout_session_id);
            $muscle = $set->exercise_id === null ? null : $muscles->get((int) $set->exercise_id);
            if (! $set->is_logged || $session === null || $session->mesocycle_id === null || $muscle === null) {
                continue;
            }
            $working[] = new LoggedWorkingSet((string) $session->mesocycle_id, (int) $session->week, $muscle);
        }

        return $working;
    }

    /**
     * ANA-009, EX-013: the exercises the user can see that have a last-used date, most recent first.
     *
     * @return list<Exercise>
     */
    public function usedExercises(): array
    {
        $used = $this->exercises->filter(fn (Exercise $exercise) => isset($this->lastUsed[(int) $exercise->id]))->values()->all();
        usort($used, fn (Exercise $a, Exercise $b) => [$this->lastUsed[(int) $b->id], $a->id] <=> [$this->lastUsed[(int) $a->id], $b->id]);

        return $used;
    }
}
