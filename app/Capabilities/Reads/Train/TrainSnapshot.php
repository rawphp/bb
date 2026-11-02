<?php

namespace App\Capabilities\Reads\Train;

use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Model\ExerciseSlot;
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
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * What the Train reads and the mesocycle writes look at, read once per invoke, and the answers they share: which
 * mesocycle is current, when the next one starts, which phase covers a date (MESO-001, MESO-003, MESO-060, MESO-064,
 * MESO-065). A write refuses exactly what a read does not offer, because both ask here and {@see MesocycleCalendar}.
 *
 * Dates are whole days at midnight UTC, read from their Y-m-d.
 */
final readonly class TrainSnapshot
{
    public const NOT_CHOSEN = 'Exercise not chosen yet';

    /**
     * @param  Collection<int, Mesocycle>  $mesocycles  saved and drafts
     * @param  Collection<int, Phase>  $phases
     * @param  Collection<int, WorkoutSession>  $sessions  of the mesocycles the invoke is about
     * @param  Collection<int, WorkoutSet>  $sets  of those sessions
     * @param  Collection<int, MuscleFeedback>  $feedback  of those sessions
     * @param  Collection<int, PlanWeek>  $planWeeks
     * @param  Collection<int, DayTarget>  $dayTargets
     * @param  Collection<int, Exercise>  $exercises  the ones the user can see
     */
    public function __construct(
        public DateTimeImmutable $today,
        public ?Plan $plan,
        public ?Profile $profile,
        public Collection $mesocycles,
        public Collection $phases,
        public Collection $sessions,
        public Collection $sets,
        public Collection $feedback,
        public Collection $planWeeks,
        public Collection $dayTargets,
        public Collection $exercises,
    ) {}

    /**
     * Everything for one user. The sessions, sets and feedback are those of `$mesocycleId` and of the current
     * mesocycle; day targets and exercises come only with `$mesocycleId`.
     */
    public static function load(User $user, ?string $mesocycleId = null): self
    {
        $base = new self(
            today: self::day(CarbonImmutable::now()),
            plan: Plan::query()->where('user_id', $user->id)->first(),
            profile: Profile::query()->where('user_id', $user->id)->first(),
            mesocycles: Mesocycle::query()->where('user_id', $user->id)->get(),
            phases: Phase::query()->where('user_id', $user->id)->orderBy('start_date')->get(),
            sessions: new Collection,
            sets: new Collection,
            feedback: new Collection,
            planWeeks: PlanWeek::query()->where('user_id', $user->id)->get(),
            dayTargets: new Collection,
            exercises: new Collection,
        );

        $ids = array_values(array_filter([$mesocycleId, $base->current()?->id]));
        $sessions = WorkoutSession::query()->whereIn('mesocycle_id', $ids)->get();
        $sessionIds = $sessions->modelKeys();
        $focus = $mesocycleId === null ? null : $base->mesocycles->firstWhere('id', $mesocycleId);

        return new self(
            today: $base->today,
            plan: $base->plan,
            profile: $base->profile,
            mesocycles: $base->mesocycles,
            phases: $base->phases,
            sessions: $sessions,
            sets: WorkoutSet::query()->whereIn('workout_session_id', $sessionIds)->orderBy('position')->get(),
            feedback: MuscleFeedback::query()->whereIn('workout_session_id', $sessionIds)->get(),
            planWeeks: $base->planWeeks,
            dayTargets: $focus === null ? new Collection : $base->dayTargetsFor($user, $focus),
            exercises: $focus === null ? new Collection : Exercise::query()->visibleTo($user)->orderBy('name')->get(),
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
            ->filter(fn (Mesocycle $m) => ! $m->is_draft && $m->start_date !== null && $m->weeks !== null)
            ->values();
    }

    /** MESO-007: the one unfinished build. */
    public function draft(): ?Mesocycle
    {
        return $this->mesocycles->filter(fn (Mesocycle $m) => $m->is_draft)->sortByDesc('updated_at')->first();
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
            start: $calendar->nextMesocycleStart($current->active_rest),
            recommendsActiveRest: $this->recommendsActiveRest($current),
            tookActiveRest: $current->active_rest,
        );
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

        return $next !== null && $next->mesocycle->is($source) ? $next->start : $this->defaultStart();
    }

    /**
     * MESO-065: the phase whose priority preset a copy of `$source` starting on `$start` takes, because it has a
     * different type from the source's phase; null when the old priorities carry over.
     */
    public function priorityReset(Mesocycle $source, DateTimeImmutable $start): ?Phase
    {
        $old = $this->phase($source->phase_id);
        $new = $this->phaseCovering($start);

        return $old !== null && $new !== null && $old->type !== $new->type ? $new : null;
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
        );
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
        $grid = $this->grid();
        if ($grid === null) {
            return $this->today;
        }
        $index = $grid->weekIndexContaining($this->today);
        if ($index === null) {
            return $grid->start(0);
        }

        return $grid->isBoundary($this->today) ? $this->today : $grid->start($index + 1);
    }

    /** MESO-004: a start moves to the first day of its plan week. */
    public function snapped(DateTimeImmutable $date): DateTimeImmutable
    {
        $grid = $this->grid();

        return $grid === null ? $date : $grid->start($grid->weekIndexContaining($date) ?? 0);
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
            sessions: $sessions($mesocycle->dayRows()),
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

    public function exercise(?int $id): ?Exercise
    {
        return $id === null ? null : $this->exercises->firstWhere('id', $id);
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

    /** @return Collection<int, DayTarget> the day targets over the mesocycle's weeks */
    private function dayTargetsFor(User $user, Mesocycle $mesocycle): Collection
    {
        if ($mesocycle->start_date === null || $mesocycle->weeks === null) {
            return new Collection;
        }
        $calendar = $this->calendar($mesocycle);
        $first = $calendar->planWeek(0)->start;
        $last = $calendar->planWeek($mesocycle->weeks - 1)->end() ?? $first;

        return DayTarget::query()->where('user_id', $user->id)
            ->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])
            ->get(['id', 'date', 'kcal']);
    }
}
