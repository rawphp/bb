<?php

namespace App\Support\Workouts;

use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Progression\LoggedExercise;
use App\Domain\Progression\LoggedSet;
use App\Domain\Progression\RIRSchedule;
use App\Domain\Progression\Underperformance;
use App\Domain\Workout\FeedbackSheets;
use App\Domain\Workout\OtherSession;
use App\Domain\Workout\ProgressionApply;
use App\Domain\Workout\ProgressionInput;
use App\Domain\Workout\ProgressionResult;
use App\Domain\Workout\SessionExercise;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * One session and what its feedback and finish rules read, loaded once per invoke: its sets and their exercises, its
 * stored answers, the user's other sessions (FB-007, FB-009), its mesocycle (FB-008, PROG-010) and the mesocycle's
 * earlier sessions (PROG-050). The rules themselves are {@see FeedbackSheets} and {@see ProgressionApply}.
 */
final class WorkoutSnapshot
{
    /**
     * @param  Collection<int, WorkoutSet>  $sets  the session's sets in order
     * @param  Collection<int, Exercise>  $exercises  every exercise the sets read here name, keyed by id
     * @param  Collection<int, WorkoutSession>  $others  the user's other sessions the rules look at
     * @param  Collection<int, WorkoutSet>  $otherSets  the sets of those sessions
     * @param  Collection<string, MuscleFeedback>  $muscleFeedback  this session's, keyed by muscle
     * @param  Collection<int, ExerciseFeedback>  $exerciseFeedback  this session's, keyed by library exercise id
     */
    private function __construct(
        public readonly WorkoutSession $session,
        public readonly ?Mesocycle $mesocycle,
        public readonly ?TrainSnapshot $train,
        public readonly Collection $sets,
        public readonly Collection $exercises,
        public readonly Collection $others,
        public readonly Collection $otherSets,
        public readonly Collection $muscleFeedback,
        public readonly Collection $exerciseFeedback,
    ) {}

    /** @throws ModelNotFoundException<WorkoutSession> when the user has no such session, which the seam answers 404 */
    public static function load(User $user, string $workoutId): self
    {
        $session = WorkoutSession::query()->where('user_id', $user->id)->whereKey($workoutId)->firstOrFail();
        $mesocycle = $session->mesocycle_id === null ? null
            : Mesocycle::query()->where('user_id', $user->id)->whereKey($session->mesocycle_id)->first();
        $start = $session->actual_start_at ?? CarbonImmutable::now();

        // FB-007 looks back 14 days; FB-009 looks at anything later, and at the rest of the mesocycle.
        $others = WorkoutSession::query()->where('user_id', $user->id)->whereKeyNot($session->id)
            ->where(fn ($query) => $query
                ->where('actual_start_at', '>=', $start->copy()->subDays(FeedbackSheets::SORENESS_WINDOW_DAYS + 1))
                ->when($mesocycle !== null, fn ($query) => $query->orWhere('mesocycle_id', $mesocycle?->id)))
            ->get();
        $sets = WorkoutSet::query()->where('workout_session_id', $session->id)->orderBy('position')->orderBy('id')->get();
        $otherSets = WorkoutSet::query()->whereIn('workout_session_id', $others->modelKeys())->orderBy('position')->orderBy('id')->get();
        $exerciseIds = $sets->concat($otherSets)->pluck('exercise_id')->filter()->unique()->values()->all();

        return new self(
            session: $session,
            mesocycle: $mesocycle,
            train: $mesocycle === null ? null : TrainSnapshot::load($user, $mesocycle->id),
            sets: $sets,
            exercises: Exercise::query()->whereIn('id', $exerciseIds)->get()->keyBy('id'),
            others: $others,
            otherSets: $otherSets,
            muscleFeedback: MuscleFeedback::query()->where('workout_session_id', $session->id)->get()
                ->keyBy(fn (MuscleFeedback $row) => $row->muscle_group->value),
            exerciseFeedback: ExerciseFeedback::query()->where('workout_session_id', $session->id)->get()->keyBy('exercise_id'),
        );
    }

    public function isStarted(): bool
    {
        return $this->session->actual_start_at !== null;
    }

    public function isFinished(): bool
    {
        return $this->session->actual_end_at !== null;
    }

    /** FB-008, PROG-040: the mesocycle's last week, or a plan week flagged a deload. */
    public function isDeload(): bool
    {
        $mesocycle = $this->mesocycle;
        if ($mesocycle === null || $mesocycle->weeks === null) {
            return false;
        }
        if ($this->train !== null && $mesocycle->isSaved()) {
            return $this->train->isDeloadWeek($this->train->calendar($mesocycle), $this->session->week - 1);
        }

        return RIRSchedule::isDeload($this->session->week, $mesocycle->weeks);
    }

    /** The exercise a set names, else null (a deleted custom exercise). */
    public function exercise(?int $id): ?Exercise
    {
        return $id === null ? null : $this->exercises->get($id);
    }

    /**
     * The session's exercises in its order, each once, from its sets.
     *
     * @return list<SessionExercise>
     */
    public function sessionExercises(): array
    {
        $exercises = [];
        foreach ($this->sets->groupBy('exercise_id') as $id => $sets) {
            $exercise = $this->exercise((int) $id);
            if ($exercise === null) {
                continue;
            }
            $exercises[] = new SessionExercise(
                (int) $id, $exercise->name, $exercise->muscle_group, $sets->count(),
                $sets->every(fn (WorkoutSet $set) => $set->is_logged || $set->is_skipped),
            );
        }

        return $exercises;
    }

    public function sheets(): FeedbackSheets
    {
        return new FeedbackSheets(
            exercises: $this->sessionExercises(),
            start: $this->start(),
            isDeload: $this->isDeload(),
            isSkipped: $this->session->is_skipped,
            others: $this->others->map(fn (WorkoutSession $other) => new OtherSession(
                startedAt: $other->actual_start_at?->toDateTimeImmutable(),
                isSkipped: $other->is_skipped,
                isLaterInMesocycle: $other->mesocycle_id !== null && $other->mesocycle_id === $this->session->mesocycle_id
                    && [$other->week, $other->day] > [$this->session->week, $this->session->day],
                workingMuscles: $this->muscles($other, working: true),
                muscles: $this->muscles($other, working: false),
            ))->values()->all(),
            answers: $this->muscleFeedback->map(fn (MuscleFeedback $row) => self::answers($row))->all(),
            jointPain: $this->exerciseFeedback->map(fn (ExerciseFeedback $row) => JointPain::from($row->joint_pain))->all(),
        );
    }

    /**
     * PROG-010 to PROG-019, PROG-050, PROG-051: next week's counts for the session's day from the session as it ran
     * and its stored answers. Null in a deload week, outside a mesocycle, or for a day the rotation does not hold.
     */
    public function progression(): ?ProgressionResult
    {
        $mesocycle = $this->mesocycle;
        $days = array_values($mesocycle->days ?? []);
        $dayIndex = $this->session->day - 1;
        if ($mesocycle === null || $this->train === null || $this->isDeload() || ! isset($days[$dayIndex])) {
            return null;
        }

        $counts = [];
        foreach ($this->sets as $set) {
            if ($set->exercise_id !== null) {
                $counts[$set->exercise_id] = ($counts[$set->exercise_id] ?? 0) + 1;
            }
        }

        return ProgressionApply::apply(new ProgressionInput(
            days: $mesocycle->dayRows(),
            dayIndex: $dayIndex,
            phase: $this->train->phaseType($mesocycle) ?? PhaseType::Maintenance,
            isResensitization: $mesocycle->is_resensitization,
            weeks: (int) $mesocycle->weeks,
            priorities: TrainSnapshot::priorities($mesocycle),
            experience: ExperienceLevel::tryFrom((string) $this->train->profile?->experience) ?? ExperienceLevel::Intermediate,
            setCounts: $counts,
            muscleAnswers: $this->muscleFeedback->map(fn (MuscleFeedback $row) => self::answers($row))->all(),
            jointPain: $this->exerciseFeedback->map(fn (ExerciseFeedback $row) => JointPain::from($row->joint_pain))->all(),
            held: $this->held(),
            now: $this->start(),
        ));
    }

    /**
     * PROG-050: the muscles that missed their rep targets in this session and the one before that trained them, over
     * the mesocycle's sessions not skipped, oldest first.
     *
     * @return list<MuscleGroup>
     */
    public function held(): array
    {
        $earlier = $this->others
            ->filter(fn (WorkoutSession $other) => $other->mesocycle_id !== null && $other->mesocycle_id === $this->session->mesocycle_id
                && ! $other->is_skipped && [$other->week, $other->day] < [$this->session->week, $this->session->day])
            ->sortBy(fn (WorkoutSession $other) => [$other->week, $other->day])
            ->map(fn (WorkoutSession $other) => $this->logged($this->otherSets->where('workout_session_id', $other->id)))
            ->values()->all();

        return Underperformance::flaggedMuscles([...$earlier, $this->logged($this->sets)]);
    }

    /** The session's start, as FB-007 and FB-009 compare it; now when it has not started. */
    private function start(): DateTimeImmutable
    {
        return ($this->session->actual_start_at ?? CarbonImmutable::now())->toDateTimeImmutable();
    }

    /**
     * A session's sets as the flags read them: its logged working sets that had a rep target, by exercise in order.
     *
     * @param  Collection<int, WorkoutSet>  $sets
     * @return list<LoggedExercise>
     */
    private function logged(Collection $sets): array
    {
        $logged = [];
        foreach ($sets->groupBy('exercise_id') as $id => $rows) {
            $exercise = $this->exercise((int) $id);
            $done = $rows->filter(fn (WorkoutSet $set) => $set->is_logged && ! $set->is_skipped
                && $set->target_reps !== null && $set->actual_reps !== null)
                ->map(fn (WorkoutSet $set) => new LoggedSet((float) ($set->actual_weight_kg ?? 0), (int) $set->target_reps, (int) $set->actual_reps))
                ->values()->all();
            if ($exercise !== null && $done !== []) {
                $logged[] = new LoggedExercise(
                    $exercise->name, $exercise->muscle_group,
                    new ClosedRange((int) $exercise->rep_range_min, (int) $exercise->rep_range_max), $done,
                );
            }
        }

        return $logged;
    }

    /** @return list<MuscleGroup> the muscles a session holds a set of, or with `$working` a logged working set */
    private function muscles(WorkoutSession $session, bool $working): array
    {
        return $this->otherSets
            ->filter(fn (WorkoutSet $set) => $set->workout_session_id === $session->id && (! $working || ($set->is_logged && ! $set->is_skipped)))
            ->map(fn (WorkoutSet $set) => $this->exercise($set->exercise_id)?->muscle_group)
            ->filter()->unique()->values()->all();
    }

    private static function answers(MuscleFeedback $row): FeedbackAnswers
    {
        return new FeedbackAnswers(
            soreness: Soreness::tryFrom((string) $row->soreness),
            pump: Pump::tryFrom((string) $row->pump),
            volume: VolumeFeedback::tryFrom((string) $row->volume),
        );
    }
}
