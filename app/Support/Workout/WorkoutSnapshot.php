<?php

namespace App\Support\Workout;

use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Pump;
use App\Domain\Model\Session;
use App\Domain\Model\Soreness;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\WeightUnit;
use App\Domain\Placement\ClockTime;
use App\Domain\Progression\Deload;
use App\Domain\Progression\FeedbackSheet;
use App\Domain\Progression\FeedbackSheets;
use App\Domain\Progression\JointPainSwap;
use App\Domain\Progression\LoadRules;
use App\Domain\Progression\LoggedExercise;
use App\Domain\Progression\LoggedSet;
use App\Domain\Progression\PlannedLoad;
use App\Domain\Progression\ProgressionApply;
use App\Domain\Progression\ProgressionInput;
use App\Domain\Progression\ProgressionResult;
use App\Domain\Progression\RecoveryLoad;
use App\Domain\Progression\RecoveryLoadInputs;
use App\Domain\Progression\RecoveryLoadResult;
use App\Domain\Progression\RIRSchedule;
use App\Domain\Progression\SetLoad;
use App\Domain\Progression\SetPlan;
use App\Domain\Progression\SetPlanInputs;
use App\Domain\Progression\Underperformance;
use App\Domain\Progression\WorkingSet;
use App\Domain\Progression\WorkoutSetType;
use App\Domain\Progression\WorkoutStatus;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

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

    /** @var ?list<list<LoggedExercise>> the earlier sessions as the flags read them, worked out once */
    private ?array $earlierLogged = null;

    /** @var ?array<int, JointPain> last week's same-day joint answers by exercise id, read once (PROG-018) */
    private ?array $lastWeekPain = null;

    /** @var array<int, list<JointPain>> each exercise's joint answers in order, read once per batch (EX-009) */
    private array $painHistory = [];

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
        self::sessionOrFail($train, $mesocycle, $week, $day);
    }

    /**
     * The session `$week` / `$day` (both from 1) of the user's saved mesocycle, whatever the case of its id.
     *
     * @throws ModelNotFoundException<Mesocycle>
     */
    public static function load(User $user, string $mesocycleId, int $week, int $day, bool $locked = false): self
    {
        $train = TrainSnapshot::load($user, $mesocycleId, $locked);
        $mesocycle = $train->mesocycle($mesocycleId);
        self::sessionOrFail($train, $mesocycle, $week, $day);
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $session = self::rowOf($train, $mesocycle, $week, $day);
        $start = self::instant($session?->actual_start_at) ?? $now;
        $history = self::read(WorkoutSession::query()->where('user_id', $user->id)
            ->where('is_skipped', false)->whereNotNull('actual_start_at')
            ->when($session !== null, fn ($query) => $query->whereKeyNot($session->id))
            ->where(fn ($query) => $query->where('mesocycle_id', $mesocycle->id)
                ->orWhere('actual_start_at', '>=', $start->modify('-'.FeedbackSheets::SORENESS_WINDOW_DAYS.' days')->format('Y-m-d H:i:s'))), $locked)
            ->get();
        $date = self::dateOf($train, $mesocycle, $week, $day);

        return new self(
            train: $train,
            mesocycle: $mesocycle,
            week: $week,
            day: $day,
            blockRows: $session === null ? new Collection : self::read(WorkoutExercise::query()->where('workout_session_id', $session->id)->orderBy('position'), $locked)->get(),
            exerciseFeedback: $session === null ? new Collection : self::read(ExerciseFeedback::query()->where('workout_session_id', $session->id), $locked)->get(),
            history: $history,
            historySets: self::read(WorkoutSet::query()->whereIn('workout_session_id', $history->modelKeys()), $locked)->with('exercise:id,muscle_group')->get(),
            loadSteps: self::read(ExerciseLoadStep::query()->where('user_id', $user->id), $locked)->get(),
            notes: self::read(ExerciseNote::query()->where('user_id', $user->id), $locked)->get(),
            dayTargets: $date === null ? new Collection : self::read(DayTarget::query()->where('user_id', $user->id)->whereDate('date', $date->format('Y-m-d')), $locked)->get(),
            now: $now,
        );
    }

    /**
     * The session a workout id names (OFF-021): one of the user's saved mesocycles, a week and a day, matched
     * against the ids {@see WorkoutIds} gives them. There are only a few to try: each week has at most the days it
     * stores, and {@see load()} refuses one the plan does not run.
     *
     * @throws ModelNotFoundException<WorkoutSession> when it names none of the user's sessions
     */
    /**
     * The session a workout id names, for a write: the mesocycle row is locked for update first, so workout writes
     * to one mesocycle run one at a time, and everything is then read with locks, so this write sees what the one
     * before it saved. Call it inside the write's transaction; the locks last until it ends.
     *
     * @throws ModelNotFoundException<WorkoutSession> when it names none of the user's sessions
     */
    public static function lockFor(User $user, string $workoutId): self
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('WorkoutSnapshot::lockFor() takes locks that last until a transaction ends; call it inside one.');
        }
        [$mesocycle, $week, $day] = self::locate($user, $workoutId);
        Mesocycle::query()->whereKey($mesocycle->id)->lockForUpdate()->firstOrFail();

        return self::load($user, (string) $mesocycle->id, $week, $day, locked: true);
    }

    public static function forWorkout(User $user, string $workoutId): self
    {
        [$mesocycle, $week, $day] = self::locate($user, $workoutId);

        return self::load($user, (string) $mesocycle->id, $week, $day);
    }

    /**
     * The mesocycle, week and day a workout id names, without loading the session: what a write needs before it
     * takes its lock.
     *
     * @return array{Mesocycle, int, int}
     *
     * @throws ModelNotFoundException<WorkoutSession> when it names none of the user's sessions
     */
    public static function locate(User $user, string $workoutId): array
    {
        $wanted = strtolower($workoutId);
        foreach (Mesocycle::savedBy($user)->get() as $mesocycle) {
            for ($week = 1; $week <= (int) $mesocycle->weeks; $week++) {
                $days = count($mesocycle->earlierWeekRows()[$week - 1] ?? $mesocycle->dayRows());
                for ($day = 1; $day <= $days; $day++) {
                    if (WorkoutIds::workout((string) $mesocycle->id, $week, $day) === $wanted) {
                        return [$mesocycle, $week, $day];
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
        return self::sessionOrFail($this->train, $this->mesocycle, $this->week, $this->day);
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
        $workoutId = $this->workoutId();
        $blocks = [];
        foreach ($this->dayRow()['slots'] ?? [] as $slot) {
            $exercise = $this->train->exercise($slot['exercise_id']);
            if ($exercise === null || ($isDeload && in_array(MuscleGroup::from($slot['muscle_group']), Deload::REMOVED_MUSCLES, true))) {
                continue;
            }
            // PROG-018, PROG-051, PROG-053: the recovery flags move the targets the session starts with.
            $loads = $this->recovery($exercise, $this->targets($exercise, $isDeload ? Deload::sets($slot['sets']) : $slot['sets'], $slot['carried'] ?? null))->targets;
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
     * PROG-020, PROG-041, MESO-061: an exercise's planned targets for `$sets` sets, before the recovery flags.
     *
     * @param  ?array{weight_kg: float, reps: int}  $carried
     * @return list<PlannedLoad>
     */
    private function targets(Exercise $exercise, int $sets, ?array $carried): array
    {
        $isDeload = $this->isDeload();

        return SetPlan::targets(new SetPlanInputs(
            sets: $sets,
            lastWeek: $this->lastWeekLoads($exercise),
            carried: $carried === null ? null : new SetLoad($this->weightOf($carried['weight_kg'], $exercise), $carried['reps']),
            rir: $this->rir(),
            deloadHalf: $isDeload ? Deload::half($this->day - 1, count($this->train->domain($this->mesocycle)->sessionsInWeek($this->week - 1))) : null,
            isFatLoss: $this->isFatLoss(),
            experience: $this->train->experience(),
            rules: $this->rules($exercise),
        ));
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

    // MARK: answers (FB-002 to FB-005, WO-040)

    /** @return list<FeedbackSheet> FB-001: the session's sheets, due or not */
    public function sheets(): array
    {
        return FeedbackSheets::sheets(array_map(fn (WorkoutBlock $block) => $block->feedback(), $this->blocks()), $this->isDeload(), $this->status());
    }

    /** The muscle's stored soreness, pump and volume for this session. */
    public function muscleAnswers(MuscleGroup $muscle): ?MuscleFeedback
    {
        $session = $this->session();

        return $session === null ? null
            : $this->train->feedback->first(fn (MuscleFeedback $row) => $row->workout_session_id === $session->id && $row->muscle_group === $muscle);
    }

    /** @return array<int, JointPain> this session's FB-002 answers, by library exercise id */
    public function jointAnswers(): array
    {
        $session = $this->session();
        $answers = [];
        foreach ($this->exerciseFeedback as $row) {
            $pain = JointPain::tryFrom((string) $row->joint_pain);
            if ($session !== null && $row->workout_session_id === $session->id && $pain !== null) {
                $answers[(int) $row->exercise_id] = $pain;
            }
        }

        return $answers;
    }

    /** FB-007. */
    public function asksSoreness(MuscleGroup $muscle): bool
    {
        return FeedbackSheets::asksSoreness($this->start(), $this->lastTrained($muscle));
    }

    /**
     * WO-040: the muscles whose sheet is due and not fully answered. They block Finish.
     *
     * @return list<MuscleGroup>
     */
    public function pendingFeedback(): array
    {
        return array_values(array_map(
            fn (FeedbackSheet $sheet) => $sheet->muscle,
            array_filter($this->sheets(), fn (FeedbackSheet $sheet) => $sheet->isDue && ! $this->isAnswered($sheet)),
        ));
    }

    /** WO-040: pump, volume, soreness when asked, and every joint question of the sheet. */
    public function isAnswered(FeedbackSheet $sheet): bool
    {
        $row = $this->muscleAnswers($sheet->muscle);

        return FeedbackSheets::isAnswered($sheet, $this->asksSoreness($sheet->muscle), $row?->soreness, $row?->pump, $row?->volume, array_keys($this->jointAnswers()));
    }

    /** WO-040: the session has sets and every one is logged or skipped. */
    public function allSetsDone(): bool
    {
        $sets = array_merge(...array_map(fn (WorkoutBlock $block) => $block->sets, $this->blocks()));

        return $sets !== [] && array_all($sets, fn (WorkoutSetRow $set) => $set->isDone());
    }

    // MARK: next week (PROG-010 to PROG-019, PROG-050, PROG-051) and recovery (PROG-018, PROG-051, PROG-053, EX-009)

    /**
     * Next week's counts for the session's day of the rotation, from the session as it ran and its stored answers.
     * Null before it starts, in a deload week, for a day only an earlier week ran (SCH-039), or once a later week's
     * session of the same day has started: only the latest occurrence sets next week.
     */
    public function progression(): ?ProgressionResult
    {
        $session = $this->session();
        $dayIndex = array_search($this->plan()->id, array_column($this->mesocycle->dayRows(), 'id'), true);
        if ($session === null || $this->status() === WorkoutStatus::NotStarted || $this->isDeload() || $dayIndex === false
            || $this->mine()->contains(fn (WorkoutSession $other) => $other->day === $this->day && $other->week > $this->week
                && $other->actual_start_at !== null && ! $other->is_skipped)) {
            return null;
        }

        // PROG-019: the sets each slot ran: those of the exercise workout_start wrote for it, still its exercise.
        $ran = [];
        $sets = $this->setsOf($session);
        foreach ($this->mesocycle->dayRows()[(int) $dayIndex]['slots'] as $slot) {
            $block = WorkoutIds::block($this->workoutId(), $slot['id']);
            $ran[$slot['id']] = $sets->filter(fn (WorkoutSet $set) => strcasecmp((string) $set->workout_exercise_id, $block) === 0
                && $slot['exercise_id'] !== null && (int) $set->exercise_id === $slot['exercise_id'])->count();
        }
        $answers = [];
        foreach ($this->train->feedback as $row) {
            if ($row->workout_session_id === $session->id) {
                $answers[$row->muscle_group->value] = new FeedbackAnswers(
                    soreness: Soreness::tryFrom((string) $row->soreness),
                    pump: Pump::tryFrom((string) $row->pump),
                    volume: VolumeFeedback::tryFrom((string) $row->volume),
                );
            }
        }

        return ProgressionApply::apply(new ProgressionInput(
            days: $this->mesocycle->dayRows(),
            dayIndex: (int) $dayIndex,
            phase: $this->train->phaseType($this->mesocycle) ?? PhaseType::Maintenance,
            isResensitization: $this->mesocycle->is_resensitization,
            weeks: (int) $this->mesocycle->weeks,
            priorities: TrainSnapshot::priorities($this->mesocycle),
            experience: $this->train->experience(),
            ranSets: $ran,
            muscleAnswers: $answers,
            jointPain: $this->jointAnswers(),
            held: Underperformance::flaggedMuscles([...$this->earlierLogged(), $this->logged($session)]),
            now: $this->start(),
        ));
    }

    /**
     * PROG-020 with the recovery flags on top (rp-clone WorkoutModel+Recovery): an exercise's targets and why they
     * moved, if they did. Week 1 and a deload week keep the planned targets. Weights are in the user's unit.
     *
     * @param  list<PlannedLoad>  $planned
     */
    private function recovery(Exercise $exercise, array $planned): RecoveryLoadResult
    {
        $lastWeek = $this->lastWeekLoads($exercise);
        if ($this->isDeload() || $lastWeek === []) {
            return new RecoveryLoadResult($planned, null);
        }
        $earlier = $this->earlierSessions();
        $flagged = Underperformance::flaggedMuscles($this->earlierLogged());

        return RecoveryLoad::apply(new RecoveryLoadInputs(
            planned: $planned,
            lastWeek: $lastWeek,
            rir: $this->rir(),
            isDeload: false,
            isFatLoss: $this->isFatLoss(),
            rules: $this->rules($exercise),
            muscle: $exercise->muscle_group,
            flaggedMuscle: in_array($exercise->muscle_group, $flagged, true) ? $exercise->muscle_group : null,
            history: $earlier->map(fn (WorkoutSession $s) => $this->loggedExercise($s, $exercise))->filter()->values()->all(),
            lastWeekJointPain: $this->lastWeekPain()[(int) $exercise->id] ?? null,
        ));
    }

    /** WO-002: why an exercise's weight is not the plain PROG-020 one this week; null when it is. */
    public function recoveryNote(Exercise $exercise, int $sets): ?string
    {
        return $this->recovery($exercise, $this->targets($exercise, $sets, null))->note;
    }

    /**
     * EX-009: "{exercise} hurt 2 sessions running. Consider swapping it." while the exercise's latest two joint answers
     * (FB-002), on any day or mesocycle, are both Moderate or A lot. Answers are ordered by the start of their session
     * (actual, else planned), then week, day and row id.
     */
    public function swapSuggestion(Exercise $exercise): ?string
    {
        $id = (int) $exercise->id;
        if (! isset($this->painHistory[$id])) {
            // One read for every exercise of the session, so the screen asks once rather than per exercise.
            $ids = array_values(array_unique([$id, ...array_map(fn (WorkoutBlock $block) => (int) $block->exercise->id, $this->blocks())]));
            $this->painHistory = array_replace($this->painHistory, $this->painHistoryOf($ids));
        }

        return JointPainSwap::isSuggested($this->painHistory[$id] ?? []) ? JointPainSwap::suggestion($exercise->name) : null;
    }

    /**
     * EX-009: each exercise's joint answers, oldest first: by the start of their session (actual, else planned), then
     * week, day and row id. An exercise with none has an empty list.
     *
     * @param  list<int>  $ids
     * @return array<int, list<JointPain>>
     */
    private function painHistoryOf(array $ids): array
    {
        $user = (int) $this->mesocycle->user_id;
        $rows = ExerciseFeedback::query()->where('user_id', $user)->whereIn('exercise_id', $ids)->get();
        $places = WorkoutSession::query()->where('user_id', $user)->whereIn('id', $rows->pluck('workout_session_id')->unique())->get()
            ->mapWithKeys(fn (WorkoutSession $session) => [$session->id => [
                self::instant($session->actual_start_at ?? $session->planned_start_at)?->getTimestamp() ?? PHP_INT_MIN, $session->week, $session->day,
            ]]);
        $history = array_fill_keys($ids, []);
        foreach ($rows->filter(fn (ExerciseFeedback $row) => $places->has($row->workout_session_id))
            ->sortBy(fn (ExerciseFeedback $row) => [...$places->get($row->workout_session_id), (string) $row->id]) as $row) {
            $pain = JointPain::tryFrom((string) $row->joint_pain);
            if ($pain !== null) {
                $history[(int) $row->exercise_id][] = $pain;
            }
        }

        return $history;
    }

    /** @return array<int, JointPain> PROG-018: last week's same-day joint answers, by exercise id */
    private function lastWeekPain(): array
    {
        if ($this->lastWeekPain === null) {
            $last = $this->earlierSessions()->first(fn (WorkoutSession $s) => $s->week === $this->week - 1 && $s->day === $this->day);
            $this->lastWeekPain = $last === null ? [] : ExerciseFeedback::query()->where('workout_session_id', $last->id)->get()
                ->mapWithKeys(fn (ExerciseFeedback $row) => [(int) $row->exercise_id => JointPain::tryFrom((string) $row->joint_pain)])
                ->filter()->all();
        }

        return $this->lastWeekPain;
    }

    /** @return Collection<int, WorkoutSession> PROG-050: the mesocycle's sessions before this one, not skipped, oldest first */
    private function earlierSessions(): Collection
    {
        return $this->mine()
            ->filter(fn (WorkoutSession $s) => ! $s->is_skipped && [$s->week, $s->day] < [$this->week, $this->day])
            ->sortBy(fn (WorkoutSession $s) => [$s->week, $s->day])->values();
    }

    /** @return list<list<LoggedExercise>> */
    private function earlierLogged(): array
    {
        return $this->earlierLogged ??= $this->earlierSessions()->map(fn (WorkoutSession $s) => $this->logged($s))->values()->all();
    }

    /** @return Collection<int, WorkoutSet> a session of this mesocycle's sets, in order */
    private function setsOf(WorkoutSession $session): Collection
    {
        return $this->train->sets->filter(fn (WorkoutSet $set) => $set->workout_session_id === $session->id)->sortBy('position')->values();
    }

    /** @return list<LoggedExercise> a session as the flags read it: its logged working sets that had a rep target */
    private function logged(WorkoutSession $session): array
    {
        $logged = [];
        foreach ($this->setsOf($session)->pluck('exercise_id')->filter()->unique() as $id) {
            $exercise = $this->train->exercise((int) $id);
            $one = $exercise === null ? null : $this->loggedExercise($session, $exercise);
            if ($one !== null) {
                $logged[] = $one;
            }
        }

        return $logged;
    }

    private function loggedExercise(WorkoutSession $session, Exercise $exercise): ?LoggedExercise
    {
        $sets = $this->setsOf($session)
            ->filter(fn (WorkoutSet $set) => (int) $set->exercise_id === (int) $exercise->id && WorkingSet::counts((bool) $set->is_logged, (bool) $set->is_skipped)
                && $set->target_reps !== null && $set->actual_reps !== null)
            ->map(fn (WorkoutSet $set) => new LoggedSet($this->weightOf($set->actual_weight_kg, $exercise) ?? 0.0, (int) $set->target_reps, (int) $set->actual_reps))
            ->values()->all();

        return $sets === [] ? null : new LoggedExercise(
            $exercise->name, $exercise->muscle_group, new ClosedRange((int) $exercise->rep_range_min, (int) $exercise->rep_range_max), $sets,
        );
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

    /** @throws ModelNotFoundException<Mesocycle> for a draft, or a week or day the mesocycle does not have */
    private static function sessionOrFail(TrainSnapshot $train, Mesocycle $mesocycle, int $week, int $day): Session
    {
        return self::sessionOf($train, $mesocycle, $week, $day)
            ?? throw (new ModelNotFoundException)->setModel(Mesocycle::class, [(string) $mesocycle->id]);
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

    /**
     * A shared-lock read when `$locked`: it returns the latest commit, as a plain read inside a transaction that has
     * read before does not (REPEATABLE READ).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function read(Builder $query, bool $locked): Builder
    {
        return $locked ? $query->sharedLock() : $query;
    }
}
