<?php

namespace App\Support\Mesocycles;

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\Mesocycle as DomainMesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Session;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\Workout;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Progression\ActiveRest;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * What the Train reads and the mesocycle writes look at, read once per invoke, and the answers they share: which
 * mesocycle is current, when the next one starts, which phase covers a date (MESO-001, MESO-003, MESO-060, MESO-064,
 * MESO-065). A write refuses exactly what a read does not offer, because both ask here, {@see MesocycleCalendar} and
 * {@see MesocycleRules}.
 *
 * The exercises and the day targets are read only when asked for: the writes never need them.
 *
 * Dates are whole days at midnight UTC, read from their Y-m-d.
 */
final class TrainSnapshot
{
    public const NOT_CHOSEN = 'Exercise not chosen yet';

    /** @var ?Collection<int, DayTarget> */
    private ?Collection $dayTargetRows = null;

    /** @var ?Collection<int, Exercise> the library, keyed by id */
    private ?Collection $library = null;

    /** @var ?Collection<int, Exercise> the exercises the mesocycles and sets name, keyed by id */
    private ?Collection $named = null;

    /**
     * @param  Collection<int, Mesocycle>  $mesocycles  saved and drafts
     * @param  Collection<int, Phase>  $phases
     * @param  Collection<int, WorkoutSession>  $sessions  of the mesocycles the invoke is about
     * @param  Collection<int, WorkoutSet>  $sets  of those sessions
     * @param  Collection<int, MuscleFeedback>  $feedback  of those sessions
     * @param  Collection<int, PlanWeek>  $planWeeks
     * @param  Collection<int, DayTarget>|Closure(): Collection<int, DayTarget>  $dayTargets  over the weeks of the mesocycle the invoke is about
     * @param  Collection<int, Exercise>|Closure(?list<int>): Collection<int, Exercise>  $exercises  the ones the user can see; the loader is given the ids wanted, or null for all
     */
    public function __construct(
        public readonly DateTimeImmutable $today,
        public readonly ?Plan $plan,
        public readonly ?Profile $profile,
        public readonly Collection $mesocycles,
        public readonly Collection $phases,
        public readonly Collection $sessions,
        public readonly Collection $sets,
        public readonly Collection $feedback,
        public readonly Collection $planWeeks,
        private readonly Collection|Closure $dayTargets,
        private readonly Collection|Closure $exercises,
    ) {}

    /**
     * Everything for one user. The sessions, sets and feedback are those of `$mesocycleId` and of the current
     * mesocycle; day targets come only with `$mesocycleId`.
     *
     * `$locked` reads every user row with a shared lock, which returns the latest commit even inside a transaction
     * that has read before (MySQL's REPEATABLE READ keeps its first view for plain reads). A write that waited on a lock
     * reads this way so it sees what the write before it saved; the library's exercises are read plainly.
     */
    public static function load(User $user, ?string $mesocycleId = null, bool $locked = false): self
    {
        $base = new self(
            today: self::day(CarbonImmutable::now()),
            plan: self::read(Plan::query()->where('user_id', $user->id), $locked)->first(),
            profile: self::read(Profile::query()->where('user_id', $user->id), $locked)->first(),
            mesocycles: self::read(Mesocycle::query()->where('user_id', $user->id), $locked)->get(),
            phases: self::read(Phase::query()->where('user_id', $user->id)->orderBy('start_date'), $locked)->get(),
            sessions: new Collection,
            sets: new Collection,
            feedback: new Collection,
            planWeeks: self::read(PlanWeek::query()->where('user_id', $user->id), $locked)->get(),
            dayTargets: new Collection,
            exercises: new Collection,
        );

        $focus = $mesocycleId === null ? null : $base->findMesocycle($mesocycleId);
        $ids = array_values(array_filter([$focus?->id, $base->current()?->id]));
        $sessions = self::read(WorkoutSession::query()->whereIn('mesocycle_id', $ids), $locked)->get();
        $sessionIds = $sessions->modelKeys();

        return new self(
            today: $base->today,
            plan: $base->plan,
            profile: $base->profile,
            mesocycles: $base->mesocycles,
            phases: $base->phases,
            sessions: $sessions,
            sets: self::read(WorkoutSet::query()->whereIn('workout_session_id', $sessionIds)->orderBy('position'), $locked)->get(),
            feedback: self::read(MuscleFeedback::query()->whereIn('workout_session_id', $sessionIds), $locked)->get(),
            planWeeks: $base->planWeeks,
            dayTargets: fn (): Collection => $focus === null ? new Collection : $base->dayTargetsFor($user, $focus, $locked),
            exercises: fn (?array $ids): Collection => Exercise::query()->visibleTo($user)
                ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
                ->orderBy('name')->get(),
        );
    }

    /** The start of the day a date falls on, as midnight UTC. */
    public static function day(DateTimeInterface|string $date): DateTimeImmutable
    {
        $text = is_string($date) ? substr($date, 0, 10) : $date->format('Y-m-d');

        return new DateTimeImmutable($text, new DateTimeZone('UTC'));
    }

    // MARK: mesocycles

    /**
     * MESO-001: every saved mesocycle with dates. Drafts never count.
     *
     * @return Collection<int, Mesocycle>
     */
    public function saved(): Collection
    {
        return $this->mesocycles
            ->filter(fn (Mesocycle $m) => $m->isSaved())
            ->values();
    }

    /**
     * The user's mesocycle with this id, whatever the case of its letters (the owner check ignores it too).
     *
     * @throws ModelNotFoundException<Mesocycle> when the user has none, which the seam answers with 404
     */
    public function mesocycle(string $id): Mesocycle
    {
        return $this->findMesocycle($id) ?? throw (new ModelNotFoundException)->setModel(Mesocycle::class, [$id]);
    }

    /** MESO-007: the one unfinished build: a draft, or a row saved without dates ({@see Mesocycle::isSaved()}). */
    public function draft(): ?Mesocycle
    {
        return $this->mesocycles->filter(fn (Mesocycle $m) => ! $m->isSaved())->sortByDesc('updated_at')->first();
    }

    /** The latest saved mesocycle that has started. */
    public function current(): ?Mesocycle
    {
        return $this->saved()
            ->filter(fn (Mesocycle $m) => self::day($m->start_date) <= $this->today)
            ->sortBy(fn (Mesocycle $m) => $m->start_date)
            ->last();
    }

    /**
     * MESO-060: "Build your next mesocycle" for the current mesocycle, from its deload week or its early end, until a
     * saved mesocycle starts after it. MESO-064: an active rest it took moves the start a plan week later.
     */
    public function nextMesocycle(): ?NextMesocycle
    {
        $current = $this->current();
        if ($current === null) {
            return null;
        }
        $start = self::day($current->start_date);
        if ($this->saved()->contains(fn (Mesocycle $m) => self::day($m->start_date) > $start)) {
            return null;
        }
        $calendar = $this->calendar($current);
        if (! $calendar->isNextMesocycleDue()) {
            return null;
        }

        return new NextMesocycle(
            mesocycle: $current,
            start: $this->nextMesocycleStart($current, $current->active_rest),
            recommendsActiveRest: $this->recommendsActiveRest($current),
            tookActiveRest: $current->active_rest,
        );
    }

    private function findMesocycle(string $id): ?Mesocycle
    {
        return $this->mesocycles->first(fn (Mesocycle $m) => strcasecmp((string) $m->id, $id) === 0);
    }

    /** MESO-060: when the mesocycle after `$current` starts; MESO-064: a week later with an active rest. */
    public function nextMesocycleStart(Mesocycle $current, bool $activeRest): DateTimeImmutable
    {
        return MesocycleRules::nextMesocycleStart($this->calendar($current)->nextMesocycleStart($activeRest), $this->defaultStart());
    }

    /** MESO-002, MESO-060: the mesocycle Copy copies: the latest that has started, else the latest planned. */
    public function copySource(): ?Mesocycle
    {
        return $this->current() ?? $this->saved()->sortBy(fn (Mesocycle $m) => $m->start_date)->last();
    }

    /**
     * MESO-060: a copy's start when none is given: the next mesocycle's start when it copies the one ending, else the
     * next plan week.
     */
    public function copyStart(Mesocycle $source): DateTimeImmutable
    {
        $next = $this->nextMesocycle();

        return MesocycleRules::copyStart($next !== null && $next->mesocycle->is($source) ? $next->start : null, $this->defaultStart());
    }

    /**
     * MESO-065: the phase whose priority preset a copy of `$source` starting on `$start` takes, because it has a
     * different type from the source's phase; null when the old priorities carry over.
     */
    public function priorityReset(Mesocycle $source, DateTimeImmutable $start): ?Phase
    {
        $new = $this->phaseCovering($start);

        return MesocycleRules::priorityReset($this->phaseType($source), $new?->type) === null ? null : $new;
    }

    /**
     * MESO-065: the priorities a copy of `$source` starting on `$start` takes: the preset of the phase covering the
     * start when it has a different type from the source's phase, unless `$keep`; else the source's.
     *
     * @return ?array<string, ?string>
     */
    public function copyPriorities(Mesocycle $source, DateTimeImmutable $start, bool $keep = false): ?array
    {
        $reset = $keep ? null : $this->priorityReset($source, $start);

        return $reset === null
            ? $source->muscle_priorities
            : array_map(fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities(Column::for($reset->type, false)));
    }

    /** MESO-064, PROG-054: the mesocycle so far had two flat weeks running. */
    public function recommendsActiveRest(Mesocycle $mesocycle): bool
    {
        return ActiveRest::isRecommended($this->feedbackWeeks($mesocycle));
    }

    // MARK: time

    /** Where a saved mesocycle stands in time. */
    public function calendar(Mesocycle $mesocycle): MesocycleCalendar
    {
        $start = self::day($mesocycle->start_date);

        return new MesocycleCalendar(
            start: $start,
            weeks: (int) $mesocycle->weeks,
            endedOn: $mesocycle->ended_on === null ? null : self::day($mesocycle->ended_on),
            grid: $this->grid() ?? MesocycleCalendar::gridWithoutPlan($start),
            today: $this->today,
            nextStart: $this->saved()
                ->map(fn (Mesocycle $m) => self::day($m->start_date))
                ->filter(fn (DateTimeImmutable $other) => $other > $start)
                ->min(),
        );
    }

    /** A week of the mesocycle (0 is its first) is a deload: its last week, or a plan week flagged one. */
    public function isDeloadWeek(MesocycleCalendar $calendar, int $index): bool
    {
        return $index + 1 === $calendar->weeks || ($this->planWeekRow($calendar->planWeek($index)->start)->is_deload ?? false);
    }

    /**
     * MESO-041, PLAN-022: whether a week is a diet break. PLAN-002: it follows the phase covering the week, falling back
     * to the mesocycle's own phase past the plan's end, as {@see PlanRules::governingPhaseIndex()} does.
     */
    public function isDietBreakWeek(Mesocycle $mesocycle, MesocycleCalendar $calendar, int $index): bool
    {
        $start = $calendar->planWeek($index)->start;
        $row = $this->planWeekRow($start);

        return MesocycleRules::isDietBreakWeek(
            flagged: $row->is_diet_break ?? false,
            phase: $this->phaseCovering($start)->type ?? $this->phaseType($mesocycle),
            isDeload: $this->isDeloadWeek($calendar, $index),
            turnedOff: $row->diet_break_off ?? false,
        );
    }

    /** MESO-044: ending a fat loss mesocycle early offers a diet break next week, unless this week is one. */
    public function endOffersDietBreak(Mesocycle $mesocycle): bool
    {
        $calendar = $this->calendar($mesocycle);

        return MesocycleRules::endOffersDietBreak($this->phaseType($mesocycle), $this->isDietBreakWeek($mesocycle, $calendar, $calendar->currentWeek() - 1));
    }

    /** The plan week row starting on a day, if the plan keeps one. */
    public function planWeekRow(DateTimeImmutable $start): ?PlanWeek
    {
        return $this->planWeeks->first(fn (PlanWeek $week) => self::day($week->start_date) == $start);
    }

    /** PLAN-008: the plan's week grid; null without a plan. */
    public function grid(): ?PlanWeekGrid
    {
        return $this->plan === null ? null : new PlanWeekGrid(
            self::day($this->plan->start_date),
            PlanWeekDays::calendarWeekdayFromIso($this->plan->review_weekday),
        );
    }

    /** The ISO weekday plan weeks start on; without a plan, that of the grid given. */
    public function reviewIsoWeekday(PlanWeekGrid $grid): int
    {
        return $this->plan->review_weekday ?? PlanWeekDays::isoWeekdayFromCalendar($grid->reviewWeekday());
    }

    /** MESO-004: today when it begins a plan week, otherwise the next plan week's first day. */
    public function defaultStart(): DateTimeImmutable
    {
        return MesocycleRules::defaultStart($this->grid(), $this->today);
    }

    /** MESO-004: a start moves to the first day of its plan week. */
    public function snapped(DateTimeImmutable $date): DateTimeImmutable
    {
        return MesocycleRules::snapped($this->grid(), $date);
    }

    /**
     * MESO-045: the weekday a day added now takes: in a weekday split the first free training weekday, else none,
     * and it is numbered.
     */
    public function newDayWeekday(Mesocycle $mesocycle): ?int
    {
        $weekdays = array_column($mesocycle->dayRows(), 'weekday');
        if (! MesocycleRules::isWeekdaySplit($weekdays)) {
            return null;
        }

        return MesocycleRules::freeWeekday(
            $weekdays,
            PlanWeekDays::inPlanWeekOrder($this->trainingWeekdays(), $this->reviewIsoWeekday($this->calendar($mesocycle)->grid)),
        );
    }

    // MARK: phases

    public function phase(?string $id): ?Phase
    {
        return $id === null ? null : $this->phases->firstWhere('id', $id);
    }

    /** MESO-003, PLAN-002: the phase covering a day: from its start up to, not including, its end. */
    public function phaseCovering(DateTimeImmutable $day): ?Phase
    {
        return $this->phases->first(fn (Phase $phase) => self::day($phase->start_date) <= $day
            && ($phase->end_date === null || $day < self::day($phase->end_date)));
    }

    public function phaseType(Mesocycle $mesocycle): ?PhaseType
    {
        return $this->phase($mesocycle->phase_id)?->type;
    }

    // MARK: the profile

    /** @return list<int> the ISO weekdays the user trains on */
    public function trainingWeekdays(): array
    {
        return array_values(array_map(fn (array $day) => (int) $day['weekday'], $this->profile->training_days ?? []));
    }

    // MARK: sessions

    /**
     * The mesocycle as the Domain reads it: its rotation and week lists, each slot named after its exercise.
     */
    public function domain(Mesocycle $mesocycle): DomainMesocycle
    {
        $sessions = fn (array $days): array => array_map(fn (array $day) => new Session(
            $day['id'],
            $day['label'],
            array_map(fn (array $slot) => new ExerciseSlot(
                $slot['id'],
                $this->exercise($slot['exercise_id'])->name ?? self::NOT_CHOSEN,
                MuscleGroup::from($slot['muscle_group']),
                $slot['sets'],
            ), $day['slots']),
        ), $days);

        return new DomainMesocycle(
            id: (string) $mesocycle->id,
            phase: $this->phaseType($mesocycle) ?? PhaseType::Maintenance,
            weeks: (int) $mesocycle->weeks,
            // MESO-034: an Off muscle's slots are left out of the rotation. Every change to the Off set stores the weeks
            // that ran in earlier_weeks first, as they ran ({@see rotationAsRun()}), so those keep theirs.
            sessions: MesocycleRules::withoutOff($sessions($mesocycle->dayRows()), self::off($mesocycle)),
            musclePriorities: self::priorities($mesocycle),
            isResensitization: $mesocycle->is_resensitization,
            earlierWeeks: array_map($sessions, $mesocycle->earlierWeekRows()),
            laterWeeks: $mesocycle->laterWeekIds(),
        );
    }

    /**
     * Each stored day's weekday by its id, from the rotation and the earlier weeks.
     *
     * @return array<string, ?int>
     */
    public function weekdays(Mesocycle $mesocycle): array
    {
        $weekdays = [];
        foreach ([$mesocycle->dayRows(), ...$mesocycle->earlierWeekRows()] as $days) {
            foreach ($days as $day) {
                $weekdays[$day['id']] ??= $day['weekday'];
            }
        }

        return $weekdays;
    }

    /** SCH-051: the session logged for a day, done, started or skipped; a row that only moved it does not count. */
    public function loggedSession(Mesocycle $mesocycle, int $week, int $day): ?WorkoutSession
    {
        return $this->sessions->first(fn (WorkoutSession $session) => $session->mesocycle_id === $mesocycle->id
            && $session->week === $week && $session->day === $day
            && ($session->actual_start_at !== null || $session->is_skipped));
    }

    /**
     * The working sets logged in a session.
     *
     * @return Collection<int, WorkoutSet>
     */
    public function loggedSets(WorkoutSession $session): Collection
    {
        return $this->sets->filter(fn (WorkoutSet $set) => $set->workout_session_id === $session->id
            && $set->is_logged && ! $set->is_skipped)->values();
    }

    /** @return Collection<int, DayTarget> the day targets over the weeks of the mesocycle the invoke is about */
    public function dayTargets(): Collection
    {
        return $this->dayTargetRows ??= $this->dayTargets instanceof Closure ? ($this->dayTargets)() : $this->dayTargets;
    }

    /** @return Collection<int, Exercise> every exercise the user can see, by name, keyed by id */
    public function exercises(): Collection
    {
        return $this->library ??= ($this->exercises instanceof Closure ? ($this->exercises)(null) : $this->exercises)->keyBy('id');
    }

    /** An exercise a mesocycle or a set names; only those are read, unless the library already was. */
    public function exercise(?int $id): ?Exercise
    {
        if ($id === null) {
            return null;
        }
        if ($this->library !== null || ! $this->exercises instanceof Closure) {
            return $this->exercises()->get($id);
        }

        return ($this->named ??= ($this->exercises)($this->namedExerciseIds())->keyBy('id'))->get($id);
    }

    /** MESO-004: where a build starts: its start moved to the first day of its plan week, else the default start. */
    public function buildStart(Mesocycle $build): DateTimeImmutable
    {
        return $build->start_date === null ? $this->defaultStart() : $this->snapped(self::day($build->start_date));
    }

    /** MESO-003, TBL-012: the defaults of the phase covering a build's start; balanced maintenance without one. */
    public function buildColumn(Mesocycle $build): Column
    {
        return Column::for($this->phaseCovering($this->buildStart($build))->type ?? PhaseType::Maintenance, $build->is_resensitization);
    }

    /** TBL-020: the user's experience, intermediate until the profile says. */
    public function experience(): ExperienceLevel
    {
        return ExperienceLevel::tryFrom((string) $this->profile?->experience) ?? ExperienceLevel::Intermediate;
    }

    /** @return list<MuscleGroup> MESO-034: the muscles set Off */
    public static function off(Mesocycle $mesocycle): array
    {
        $off = [];
        foreach ($mesocycle->muscle_priorities ?? [] as $muscle => $priority) {
            if ($priority === null && ($group = MuscleGroup::tryFrom($muscle)) !== null) {
                $off[] = $group;
            }
        }

        return $off;
    }

    /**
     * SCH-039, MESO-034: the rotation as it runs now, Off muscles left out. A week stored before the rotation changes
     * (a day added, the Off set changed) is stored from this, so it keeps what ran.
     *
     * @return list<array<string, mixed>>
     */
    public function rotationAsRun(Mesocycle $mesocycle): array
    {
        return MesocycleRules::withoutMuscles($mesocycle->dayRows(), self::off($mesocycle));
    }

    /**
     * MESO-003, MESO-020: the priorities a build shows and saves: the stored ones, else the preset of its phase.
     *
     * @return array<string, ?string>
     */
    public function buildPriorities(Mesocycle $build): array
    {
        $stored = $build->muscle_priorities ?? [];

        return $stored !== [] ? $stored : array_map(fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities($this->buildColumn($build)));
    }

    /** @return list<int> MESO-005: the ISO weekdays the user trains on, in plan-week order */
    public function trainingWeekdaysInPlanOrder(): array
    {
        $grid = $this->grid();

        return $grid === null ? $this->trainingWeekdays() : PlanWeekDays::inPlanWeekOrder($this->trainingWeekdays(), $this->reviewIsoWeekday($grid));
    }

    /** @return array<string, Priority> the stored priorities that are not Off, keyed by muscle */
    public static function priorities(Mesocycle $mesocycle): array
    {
        $priorities = [];
        foreach ($mesocycle->muscle_priorities ?? [] as $muscle => $priority) {
            if ($priority !== null) {
                $priorities[$muscle] = Priority::from($priority);
            }
        }

        return $priorities;
    }

    /**
     * PROG-054: the mesocycle's weeks oldest first, each its sessions not skipped with the feedback given after them.
     * A week with none is empty, which breaks a run of flat weeks.
     *
     * @return list<list<Workout>>
     */
    private function feedbackWeeks(Mesocycle $mesocycle): array
    {
        $mine = $this->sessions->filter(fn (WorkoutSession $s) => $s->mesocycle_id === $mesocycle->id && ! $s->is_skipped);
        $lastWeek = (int) $mine->max('week');
        $weeks = [];
        for ($week = 1; $week <= $lastWeek; $week++) {
            $weeks[] = $mine->filter(fn (WorkoutSession $s) => $s->week === $week)->sortBy('day')->values()
                ->map(fn (WorkoutSession $s) => new Workout(
                    id: (string) $s->id,
                    date: self::day($s->actual_start_at ?? $this->today),
                    session: new Session((string) $s->id, '', []),
                    feedback: $this->feedback->filter(fn (MuscleFeedback $row) => $row->workout_session_id === $s->id)
                        ->mapWithKeys(fn (MuscleFeedback $row) => [$row->muscle_group->value => new FeedbackAnswers(
                            soreness: Soreness::tryFrom((string) $row->soreness),
                            pump: Pump::tryFrom((string) $row->pump),
                            volume: VolumeFeedback::tryFrom((string) $row->volume),
                        )])->all(),
                ))->all();
        }

        return $weeks;
    }

    /** @return list<int> the exercises the slots of the mesocycles and the sets name */
    private function namedExerciseIds(): array
    {
        $ids = $this->sets->pluck('exercise_id')->all();
        foreach ($this->mesocycles as $mesocycle) {
            foreach ([$mesocycle->dayRows(), ...$mesocycle->earlierWeekRows()] as $days) {
                foreach ($days as $day) {
                    $ids = [...$ids, ...array_column($day['slots'], 'exercise_id')];
                }
            }
        }

        return array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));
    }

    /** @return Collection<int, DayTarget> the day targets over the mesocycle's weeks */
    private function dayTargetsFor(User $user, Mesocycle $mesocycle, bool $locked = false): Collection
    {
        if ($mesocycle->start_date === null || $mesocycle->weeks === null) {
            return new Collection;
        }
        $calendar = $this->calendar($mesocycle);
        $first = $calendar->planWeek(0)->start;
        $last = $calendar->planWeek($mesocycle->weeks - 1)->end() ?? $first;

        return DayTarget::query()->where('user_id', $user->id)
            ->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])
            ->when($locked, fn (Builder $query) => $query->sharedLock())
            ->get(['id', 'date', 'kcal']);
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
