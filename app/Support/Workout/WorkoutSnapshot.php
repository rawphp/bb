<?php

namespace App\Support\Workout;

use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Session;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Placement\ClockTime;
use App\Domain\Progression\Deload;
use App\Domain\Progression\FeedbackSheets;
use App\Domain\Progression\LoadRules;
use App\Domain\Progression\RIRSchedule;
use App\Domain\Progression\SetLoad;
use App\Domain\Progression\SetPlan;
use App\Domain\Progression\SetPlanInputs;
use App\Domain\Progression\WorkoutSetType;
use App\Domain\Progression\WorkoutStatus;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Everything one session of a mesocycle is built from, read once (rp-clone WorkoutModel): the mesocycle and its plan
 * through {@see TrainSnapshot}, the session's row, exercises and sets, its feedback, the sessions FB-007 and FB-009
 * look at, the exercises' steps and notes, and the day's meals (WO-009).
 *
 * A session not started is planned from the rules (PROG-020, PROG-041, PROG-042, MESO-061); once started it is read
 * from its rows. Weights are kept in kg and worked in the user's unit.
 */
final class WorkoutSnapshot
{
    /** @var ?list<WorkoutBlock> */
    private ?array $blocks = null;

    /**
     * @param  Collection<int, WorkoutExercise>  $blockRows  the session's exercises, by position
     * @param  Collection<int, ExerciseFeedback>  $exerciseFeedback  the session's joint answers
     * @param  Collection<int, WorkoutSession>  $history  the user's other started sessions FB-007 and FB-009 look at
     * @param  Collection<int, WorkoutSet>  $historySets  their sets
     * @param  Collection<int, ExerciseLoadStep>  $loadSteps  the user's steps (EX-012)
     * @param  Collection<int, ExerciseNote>  $notes  the user's pinned notes (EX-010)
     * @param  Collection<int, DayTarget>  $dayTargets  the session's day
     *
     * @throws ModelNotFoundException<Mesocycle> for a draft, or a week or day the mesocycle does not have
     */
    public function __construct(
        public readonly TrainSnapshot $train,
        public readonly Mesocycle $mesocycle,
        public readonly int $week,
        public readonly int $day,
        public readonly Collection $blockRows,
        public readonly Collection $exerciseFeedback,
        public readonly Collection $history,
        public readonly Collection $historySets,
        public readonly Collection $loadSteps,
        public readonly Collection $notes,
        public readonly Collection $dayTargets,
        public readonly DateTimeImmutable $now,
    ) {
        if (! $mesocycle->isSaved() || $week < 1 || $week > (int) $mesocycle->weeks || self::sessionOf($train, $mesocycle, $week, $day) === null) {
            throw (new ModelNotFoundException)->setModel(Mesocycle::class, [(string) $mesocycle->id]);
        }
    }

    /**
     * The session `$week` / `$day` (both from 1) of the user's saved mesocycle, whatever the case of its id.
     *
     * @throws ModelNotFoundException<Mesocycle>
     */
    public static function load(User $user, string $mesocycleId, int $week, int $day): self
    {
        $train = TrainSnapshot::load($user, $mesocycleId);
        $mesocycle = $train->mesocycle($mesocycleId);
        if (! $mesocycle->isSaved() || self::sessionOf($train, $mesocycle, $week, $day) === null) {
            throw (new ModelNotFoundException)->setModel(Mesocycle::class, [$mesocycleId]);
        }
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $session = self::rowOf($train, $mesocycle, $week, $day);
        $start = self::instant($session?->actual_start_at) ?? $now;
        $history = WorkoutSession::query()->where('user_id', $user->id)
            ->where('is_skipped', false)->whereNotNull('actual_start_at')
            ->when($session !== null, fn ($query) => $query->whereKeyNot($session->id))
            ->where(fn ($query) => $query->where('mesocycle_id', $mesocycle->id)
                ->orWhere('actual_start_at', '>=', $start->modify('-'.FeedbackSheets::SORENESS_WINDOW_DAYS.' days')->format('Y-m-d H:i:s')))
            ->get();
        $date = self::dateOf($train, $mesocycle, $week, $day);

        return new self(
            train: $train,
            mesocycle: $mesocycle,
            week: $week,
            day: $day,
            blockRows: $session === null ? new Collection : WorkoutExercise::query()->where('workout_session_id', $session->id)->orderBy('position')->get(),
            exerciseFeedback: $session === null ? new Collection : ExerciseFeedback::query()->where('workout_session_id', $session->id)->get(),
            history: $history,
            historySets: WorkoutSet::query()->whereIn('workout_session_id', $history->modelKeys())->with('exercise:id,muscle_group')->get(),
            loadSteps: ExerciseLoadStep::query()->where('user_id', $user->id)->get(),
            notes: ExerciseNote::query()->where('user_id', $user->id)->get(),
            dayTargets: $date === null ? new Collection : DayTarget::query()->where('user_id', $user->id)->whereDate('date', $date->format('Y-m-d'))->get(),
            now: $now,
        );
    }

    /**
     * The session a workout id names (OFF-021): one of the user's saved mesocycles, a week and a day, matched
     * against the ids {@see WorkoutIds} gives them. There are only a few to try.
     *
     * @throws ModelNotFoundException<WorkoutSession> when it names none of the user's sessions
     */
    public static function forWorkout(User $user, string $workoutId): self
    {
        $train = TrainSnapshot::load($user);
        $wanted = strtolower($workoutId);
        foreach ($train->saved() as $mesocycle) {
            $domain = $train->domain($mesocycle);
            for ($week = 1; $week <= (int) $mesocycle->weeks; $week++) {
                foreach (array_keys($domain->sessionsInWeek($week - 1)) as $index) {
                    if (WorkoutIds::workout((string) $mesocycle->id, $week, $index + 1) === $wanted) {
                        return self::load($user, (string) $mesocycle->id, $week, $index + 1);
                    }
                }
            }
        }

        throw (new ModelNotFoundException)->setModel(WorkoutSession::class, [$workoutId]);
    }

    // MARK: the session

    public function workoutId(): string
    {
        return WorkoutIds::workout((string) $this->mesocycle->id, $this->week, $this->day);
    }

    /** The day as the plan has it this week (SCH-039). */
    public function plan(): Session
    {
        /** @var Session */
        return self::sessionOf($this->train, $this->mesocycle, $this->week, $this->day);
    }

    /** The session's row: done, started or skipped first, else one that only moved it (SCH-051). */
    public function session(): ?WorkoutSession
    {
        return self::rowOf($this->train, $this->mesocycle, $this->week, $this->day);
    }

    public function status(): WorkoutStatus
    {
        return $this->session()?->status() ?? WorkoutStatus::NotStarted;
    }

    /** When the session began, or now when it has not. */
    public function start(): DateTimeImmutable
    {
        return self::instant($this->session()?->actual_start_at) ?? $this->now;
    }

    public function calendar(): MesocycleCalendar
    {
        return $this->train->calendar($this->mesocycle);
    }

    /** The day the plan puts the session on; null when it puts it on none. */
    public function date(): ?DateTimeImmutable
    {
        return self::dateOf($this->train, $this->mesocycle, $this->week, $this->day);
    }

    /** PROG-040, PLAN-022: the mesocycle's last week, or a plan week flagged a deload. */
    public function isDeload(): bool
    {
        return $this->train->isDeloadWeek($this->calendar(), $this->week - 1);
    }

    /** PROG-030: this week's target RIR; null in a deload week. */
    public function rir(): ?int
    {
        return $this->isDeload() ? null : RIRSchedule::targetRIR($this->week, (int) $this->mesocycle->weeks);
    }

    public function isFatLoss(): bool
    {
        return $this->train->phaseType($this->mesocycle)?->isFatLoss() ?? false;
    }

    public function unit(): WeightUnit
    {
        return WeightUnit::tryFrom((string) $this->train->profile?->weight_unit) ?? WeightUnit::Kg;
    }

    /**
     * WO-009, SCH-051: when the session is planned to start: its row's planned time once it moved, else the training
     * day's start on its date.
     */
    public function plannedStart(): ?DateTimeImmutable
    {
        $planned = self::instant($this->session()?->planned_start_at);
        if ($planned !== null) {
            return $planned;
        }
        $slot = $this->trainingDay();

        return $slot === null ? null : $this->date()?->modify("+{$slot['start']->minuteOfDay} minutes");
    }

    public function plannedEnd(): ?DateTimeImmutable
    {
        $session = $this->session();
        if ($session?->planned_start_at !== null) {
            return self::instant($session->planned_end_at);
        }
        $slot = $this->trainingDay();

        return $slot === null ? null : $this->plannedStart()?->modify("+{$slot['minutes']} minutes");
    }

    // MARK: weights

    /** A kg weight in the user's unit. */
    public function display(float $kg): float
    {
        return $this->unit() === WeightUnit::Lb ? UnitConversion::kgToLb($kg) : $kg;
    }

    /** A weight in the user's unit, in kg. */
    public function kg(float $weight): float
    {
        return $this->unit() === WeightUnit::Lb ? UnitConversion::lbToKg($weight) : $weight;
    }

    /** What the load maths needs about an exercise, in the user's unit (TBL-040, EX-012). */
    public function rules(Exercise $exercise): LoadRules
    {
        $step = $this->loadSteps->first(fn (ExerciseLoadStep $row) => (int) $row->exercise_id === (int) $exercise->id);

        return new LoadRules(
            loadType: $exercise->load_type->tbl040(),
            unit: $this->unit(),
            microPlates: (bool) $this->train->profile?->micro_plates,
            stepOverride: $step === null ? null : $this->display((float) $step->step_kg),
            repRange: new ClosedRange((int) $exercise->rep_range_min, (int) $exercise->rep_range_max),
        );
    }

    /** EX-010: the user's pinned note on an exercise. */
    public function pinnedNote(Exercise $exercise): ?string
    {
        return $this->notes->first(fn (ExerciseNote $note) => (int) $note->exercise_id === (int) $exercise->id)?->note;
    }

    // MARK: the exercises

    /** @return list<WorkoutBlock> the session's exercises: planned before it starts, its rows after */
    public function blocks(): array
    {
        return $this->blocks ??= $this->status() === WorkoutStatus::NotStarted ? $this->plannedBlocks() : $this->storedBlocks();
    }

    /**
     * rp-clone plannedExercises: the day's slots with a chosen exercise, PROG-042 dropping traps and forearms and
     * PROG-041 halving the sets in a deload. Each set is targeted by {@see SetPlan}; the ids are those workout_start
     * writes the rows under (OFF-021).
     *
     * @return list<WorkoutBlock>
     */
    public function plannedBlocks(): array
    {
        $isDeload = $this->isDeload();
        $sessionsInWeek = count($this->train->domain($this->mesocycle)->sessionsInWeek($this->week - 1));
        $workoutId = $this->workoutId();
        $blocks = [];
        foreach ($this->dayRow()['slots'] ?? [] as $slot) {
            $exercise = $this->train->exercise($slot['exercise_id']);
            if ($exercise === null || ($isDeload && in_array(MuscleGroup::from($slot['muscle_group']), Deload::REMOVED_MUSCLES, true))) {
                continue;
            }
            $carried = $slot['carried'] ?? null;
            $loads = SetPlan::targets(new SetPlanInputs(
                sets: $isDeload ? Deload::sets($slot['sets']) : $slot['sets'],
                lastWeek: $this->lastWeekLoads($exercise),
                carried: $carried === null ? null : new SetLoad($this->weightOf($carried['weight_kg'], $exercise), $carried['reps']),
                rir: $this->rir(),
                deloadHalf: $isDeload ? Deload::half($this->day - 1, $sessionsInWeek) : null,
                isFatLoss: $this->isFatLoss(),
                experience: ExperienceLevel::tryFrom((string) $this->train->profile?->experience) ?? ExperienceLevel::Intermediate,
                rules: $this->rules($exercise),
            ));
            $sets = [];
            foreach ($loads as $index => $load) {
                $sets[] = new WorkoutSetRow(
                    id: WorkoutIds::set($workoutId, $slot['id'], $index),
                    type: WorkoutSetType::RegularStraight,
                    targetKg: $load->weight === null ? null : $this->kg($load->weight),
                    targetReps: $load->reps,
                    actualKg: null,
                    actualReps: null,
                    isLogged: false,
                    isSkipped: false,
                );
            }
            $blocks[] = new WorkoutBlock(WorkoutIds::block($workoutId, $slot['id']), $exercise, null, $sets);
        }

        return $blocks;
    }

    /**
     * A started, finished or skipped session from its rows: each exercise in order with its sets in order. An
     * exercise left with no sets still shows.
     *
     * @return list<WorkoutBlock>
     */
    public function storedBlocks(): array
    {
        $session = $this->session();
        if ($session === null) {
            return [];
        }
        $sets = $this->train->sets->filter(fn (WorkoutSet $set) => $set->workout_session_id === $session->id)->sortBy('position')->values();
        $blocks = [];
        foreach ($this->blockRows->sortBy('position') as $row) {
            $exercise = $this->train->exercise($row->exercise_id);
            if ($exercise !== null) {
                $mine = $sets->filter(fn (WorkoutSet $set) => strcasecmp((string) $set->workout_exercise_id, (string) $row->id) === 0);
                $blocks[] = new WorkoutBlock(strtolower((string) $row->id), $exercise, $row->note, self::rows($mine));
            }
        }

        return $blocks;
    }

    /**
     * PROG-020, MESO-062: the same day's working sets of this exercise last week, as the load maths sees them.
     *
     * @return list<SetLoad>
     */
    public function lastWeekLoads(Exercise $exercise): array
    {
        $last = $this->week > 1 ? $this->mine()->first(fn (WorkoutSession $s) => $s->week === $this->week - 1 && $s->day === $this->day && ! $s->is_skipped) : null;
        if ($last === null) {
            return [];
        }

        return $this->train->sets
            ->filter(fn (WorkoutSet $set) => $set->workout_session_id === $last->id && (int) $set->exercise_id === (int) $exercise->id
                && $set->is_logged && ! $set->is_skipped && $set->actual_reps !== null)
            ->sortBy('position')
            ->map(fn (WorkoutSet $set) => new SetLoad($this->weightOf($set->actual_weight_kg, $exercise), (int) $set->actual_reps))
            ->values()->all();
    }

    // MARK: feedback (FB-007, FB-009)

    /** FB-007: when a session before this one last trained the muscle with a working set; null when none did. */
    public function lastTrained(MuscleGroup $muscle): ?DateTimeImmutable
    {
        $start = $this->start();
        $latest = null;
        foreach ($this->otherSessions() as $session) {
            $began = self::instant($session->actual_start_at);
            if ($began !== null && $began < $start && ($latest === null || $began > $latest)
                && $this->trained($session, $muscle, workingOnly: true)) {
                $latest = $began;
            }
        }

        return $latest;
    }

    /** FB-009: a session started after this one, or a later day of the mesocycle, has trained the muscle. */
    public function isLocked(MuscleGroup $muscle): bool
    {
        $mine = $this->session();
        $start = self::instant($mine?->actual_start_at);
        if ($start === null) {
            return false;
        }
        foreach ($this->otherSessions() as $other) {
            $began = self::instant($other->actual_start_at);
            $later = ($began !== null && $began > $start)
                || ($other->mesocycle_id === $mine->mesocycle_id && [$other->week, $other->day] > [$mine->week, $mine->day]);
            if ($later && $this->trained($other, $muscle, workingOnly: false)) {
                return true;
            }
        }

        return false;
    }

    // MARK: helpers

    /** @return Collection<int, WorkoutSession> this mesocycle's sessions, the user's own */
    public function mine(): Collection
    {
        return $this->train->sessions->filter(fn (WorkoutSession $s) => $s->mesocycle_id === $this->mesocycle->id
            && (int) $s->user_id === (int) $this->mesocycle->user_id);
    }

    /** @return array<string, mixed>|null the stored day the plan runs this week, with its slots */
    private function dayRow(): ?array
    {
        $id = $this->plan()->id;
        $days = $this->mesocycle->earlierWeekRows()[$this->week - 1] ?? $this->mesocycle->dayRows();

        return array_values(array_filter($days, fn (array $day) => $day['id'] === $id))[0] ?? null;
    }

    /** @return ?array{start: ClockTime, minutes: int} the profile's training day on the session's weekday */
    private function trainingDay(): ?array
    {
        $date = $this->date();
        if ($date === null) {
            return null;
        }
        foreach (TodaySnapshot::json($this->train->profile?->training_days) as $day) {
            $start = ClockTime::fromHhmm((string) ($day['start'] ?? ''));
            if ((int) $day['weekday'] === (int) $date->format('N') && $start !== null) {
                return ['start' => $start, 'minutes' => (int) ($day['minutes'] ?? 60)];
            }
        }

        return null;
    }

    /** A logged kg weight as the load maths sees it: in the user's unit, null for pure bodyweight. */
    private function weightOf(?float $kg, Exercise $exercise): ?float
    {
        return $exercise->load_type === ExerciseLoadType::Bodyweight || $kg === null ? null : $this->display($kg);
    }

    /** @return Collection<int, WorkoutSession> */
    private function otherSessions(): Collection
    {
        $id = $this->session()?->id;

        return $this->history->filter(fn (WorkoutSession $s) => $s->id !== $id && ! $s->is_skipped && $s->actual_start_at !== null);
    }

    private function trained(WorkoutSession $session, MuscleGroup $muscle, bool $workingOnly): bool
    {
        return $this->historySets->contains(fn (WorkoutSet $set) => $set->workout_session_id === $session->id
            && (! $workingOnly || ($set->is_logged && ! $set->is_skipped))
            && ($set->relationLoaded('exercise') ? $set->exercise?->muscle_group : $this->train->exercise($set->exercise_id)?->muscle_group) === $muscle);
    }

    /**
     * @param  Collection<int, WorkoutSet>  $sets
     * @return list<WorkoutSetRow>
     */
    private static function rows(Collection $sets): array
    {
        return $sets->map(fn (WorkoutSet $set) => new WorkoutSetRow(
            id: strtolower((string) $set->id),
            type: WorkoutSetType::stored((string) $set->type, $set->variant),
            targetKg: $set->target_weight_kg,
            targetReps: $set->target_reps,
            actualKg: $set->actual_weight_kg,
            actualReps: $set->actual_reps,
            isLogged: (bool) $set->is_logged,
            isSkipped: (bool) $set->is_skipped,
        ))->values()->all();
    }

    private static function sessionOf(TrainSnapshot $train, Mesocycle $mesocycle, int $week, int $day): ?Session
    {
        if (! $mesocycle->isSaved() || $week < 1 || $week > (int) $mesocycle->weeks || $day < 1) {
            return null;
        }

        return $train->domain($mesocycle)->sessionsInWeek($week - 1)[$day - 1] ?? null;
    }

    private static function rowOf(TrainSnapshot $train, Mesocycle $mesocycle, int $week, int $day): ?WorkoutSession
    {
        $rows = $train->sessions->filter(fn (WorkoutSession $s) => $s->mesocycle_id === $mesocycle->id
            && (int) $s->user_id === (int) $mesocycle->user_id && $s->week === $week && $s->day === $day);

        return $rows->first(fn (WorkoutSession $s) => $s->status() !== WorkoutStatus::NotStarted) ?? $rows->first();
    }

    /** The day the plan puts session `$week` / `$day` of a saved mesocycle on; null when it puts it on none. */
    public static function dateOf(TrainSnapshot $train, Mesocycle $mesocycle, int $week, int $day): ?DateTimeImmutable
    {
        $calendar = $train->calendar($mesocycle);
        $sessions = $train->domain($mesocycle)->sessionsInWeek($week - 1);
        $weekdays = $train->weekdays($mesocycle);

        return MesocycleCalendar::dayDates(
            $calendar->planWeek($week - 1),
            array_map(fn (Session $session) => $weekdays[$session->id] ?? null, $sessions),
            $train->trainingWeekdays(),
            $train->reviewIsoWeekday($calendar->grid),
        )[$day - 1] ?? null;
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
            default => CarbonImmutable::parse((string) $value)->toDateTimeImmutable(),
        };
    }
}
