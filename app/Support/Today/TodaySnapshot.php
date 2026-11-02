<?php

namespace App\Support\Today;

use App\Domain\Energy\EnergyRules;
use App\Domain\MealFit\Food as MealFitFood;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Model\Mesocycle as DomainMesocycle;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\CoveringMesocycle;
use App\Domain\Plan\DatedMesocycle;
use App\Domain\Plan\PlanRules;
use App\Domain\Progress\Adherence;
use App\Domain\Progress\DayAdherence;
use App\Domain\Progress\DayTotals;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Recipe\RecipeMacros;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Domain\WeekBuilder\DaySessionPlan;
use App\Domain\WeekBuilder\WeekSessions;
use App\Domain\WeekBuilder\WeekSessions\LoggedDay;
use App\Domain\WeekBuilder\WeekSessions\SessionRow;
use App\Domain\WeekBuilder\WorkoutSessionRecord;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Exercise;
use App\Models\Food;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\OffPlanEntry;
use App\Models\OtherActivity;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\RecipeIngredient;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * What Today and its week strip look at, read once per invoke for the plan week around one day, and the answers
 * they share (SCH-001 to SCH-023): what the plan puts on a day, the day's meals with what was planned and eaten, and
 * what was consumed. The plan, the mesocycles and the next-mesocycle prompt are {@see TrainSnapshot}'s.
 *
 * Dates are whole days at midnight UTC, read from their Y-m-d.
 */
final class TodaySnapshot
{
    /** @var array<string, ?array{plan: DaySessionPlan, mesocycle: ?Mesocycle}> */
    private array $planning = [];

    /**
     * @param  DateTimeImmutable  $date  The day shown.
     * @param  ?DateTimeImmutable  $lastTarget  The last day with a target.
     * @param  Collection<int, DayTarget>  $dayTargets  over the plan week around `$date`
     * @param  Collection<int, DayTargetMeal>  $meals  the meals of those targets, with what is planned and eaten
     * @param  Collection<int, OffPlanEntry>  $offPlan  over the same week
     * @param  Collection<int, OtherActivity>  $otherActivities  over the same week
     * @param  Collection<int, WeighIn>  $weighIns  on `$date`
     * @param  Collection<int, WeighInScheduleEntry>  $weighInSchedule
     * @param  string  $nowTime  the clock now, 24-hour "HH:MM"
     */
    public function __construct(
        public readonly TrainSnapshot $train,
        public readonly DateTimeImmutable $date,
        public readonly ?DateTimeImmutable $lastTarget,
        public readonly Collection $dayTargets,
        public readonly Collection $meals,
        public readonly Collection $offPlan,
        public readonly Collection $otherActivities,
        public readonly Collection $weighIns,
        public readonly Collection $weighInSchedule,
        public readonly ?Step $steps,
        public readonly string $nowTime = '12:00',
    ) {}

    /**
     * Everything Today and the week strip show for `$date` (today when null). A date outside the plan is shown at its
     * nearest edge (SCH-023). The sessions read are those of the mesocycles covering the 7 days shown, and of the
     * current one, whose feedback the next-mesocycle prompt reads (MESO-064).
     */
    public static function load(User $user, ?string $date = null): self
    {
        $today = TrainSnapshot::day(CarbonImmutable::now());
        $plan = Plan::query()->where('user_id', $user->id)->first();
        $last = DayTarget::query()->where('user_id', $user->id)->max('date');
        $lastTarget = $last === null ? null : TrainSnapshot::day((string) $last);
        $base = new TrainSnapshot(
            today: $today,
            plan: $plan,
            profile: Profile::query()->where('user_id', $user->id)->first(),
            mesocycles: Mesocycle::query()->where('user_id', $user->id)->get(),
            phases: Phase::query()->where('user_id', $user->id)->orderBy('start_date')->get(),
            sessions: new Collection,
            sets: new Collection,
            feedback: new Collection,
            planWeeks: PlanWeek::query()->where('user_id', $user->id)->get(),
            dayTargets: new Collection,
            exercises: fn (?array $ids): Collection => Exercise::query()->visibleTo($user)
                ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
                ->orderBy('name')->get(),
        );
        $probe = new self($base, $today, $lastTarget, new Collection, new Collection, new Collection, new Collection, new Collection, new Collection, null);
        $day = $date === null ? $today : $probe->insidePlan(TrainSnapshot::day($date));
        $first = $probe->weekStart($day);
        $ids = [$base->current()?->id];
        for ($offset = 0; $offset < 7; $offset++) {
            $ids[] = $probe->covering($first->modify("+{$offset} days"))?->id;
        }
        $sessions = WorkoutSession::query()->where('user_id', $user->id)->whereIn('mesocycle_id', array_values(array_unique(array_filter($ids))))->get();
        $train = new TrainSnapshot(
            today: $today,
            plan: $plan,
            profile: $base->profile,
            mesocycles: $base->mesocycles,
            phases: $base->phases,
            sessions: $sessions,
            sets: WorkoutSet::query()->whereIn('workout_session_id', $sessions->modelKeys())->orderBy('position')->get(),
            feedback: MuscleFeedback::query()->whereIn('workout_session_id', $sessions->modelKeys())->get(),
            planWeeks: $base->planWeeks,
            dayTargets: new Collection,
            exercises: fn (?array $ids): Collection => Exercise::query()->visibleTo($user)
                ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
                ->orderBy('name')->get(),
        );
        $range = [$first->format('Y-m-d'), $first->modify('+6 days')->format('Y-m-d')];
        $targets = DayTarget::query()->where('user_id', $user->id)->whereBetween('date', $range)->get();

        return new self(
            train: $train,
            date: $day,
            lastTarget: $lastTarget,
            dayTargets: $targets,
            meals: DayTargetMeal::query()->whereIn('day_target_id', $targets->modelKeys())
                ->with(['plannedFoods.food', 'plannedRecipes.sourceRecipe.ingredients.food', 'checkIn', 'planningState'])
                ->get(),
            offPlan: OffPlanEntry::query()->where('user_id', $user->id)->whereBetween('date', $range)->orderBy('time')->get(),
            otherActivities: OtherActivity::query()->where('user_id', $user->id)->whereBetween('date', $range)->get(),
            weighIns: WeighIn::query()->where('user_id', $user->id)
                ->whereBetween('weighed_at', [$day->format('Y-m-d 00:00:00'), $day->format('Y-m-d 23:59:59')])
                ->orderBy('weighed_at')->get(),
            weighInSchedule: WeighInScheduleEntry::query()->where('user_id', $user->id)->orderBy('id')->get(),
            steps: Step::query()->where('user_id', $user->id)->whereDate('date', $day->format('Y-m-d'))->first(),
            nowTime: CarbonImmutable::now()->format('H:i'),
        );
    }

    public function today(): DateTimeImmutable
    {
        return $this->train->today;
    }

    // MARK: the plan's dates

    public function planStart(): ?DateTimeImmutable
    {
        return $this->train->plan === null ? null : TrainSnapshot::day($this->train->plan->start_date);
    }

    /** SCH-023: the last day with a target, or the plan start without any. */
    public function lastDay(): ?DateTimeImmutable
    {
        $start = $this->planStart();
        if ($start === null) {
            return null;
        }

        return $this->lastTarget !== null && $this->lastTarget > $start ? $this->lastTarget : $start;
    }

    /** SCH-020, ONB-031: the earliest day to browse to: the plan start, or today before it. */
    public function firstDay(): ?DateTimeImmutable
    {
        $start = $this->planStart();

        return $start === null ? null : min($start, $this->today());
    }

    /** PLAN-008: the first day of the 7 days around `$day`, from the plan's review weekday (Monday without a plan). */
    public function weekStart(DateTimeImmutable $day): DateTimeImmutable
    {
        return self::weekStartFor($this->train->plan, $day);
    }

    private static function weekStartFor(?Plan $plan, DateTimeImmutable $day): DateTimeImmutable
    {
        $back = ((int) $day->format('N') - ($plan->review_weekday ?? 1) + 7) % 7;

        return $day->modify("-{$back} days");
    }

    public function isBeforePlan(DateTimeImmutable $day): bool
    {
        $start = $this->planStart();

        return $start === null || $day < $start;
    }

    /**
     * SCH-020, SCH-023: the last day to browse to: the last day with a target, or today after it, so today's week can
     * always be opened.
     */
    public function browseEnd(): ?DateTimeImmutable
    {
        $last = $this->lastDay();

        return $last === null ? null : max($last, $this->today());
    }

    /**
     * SCH-023: a date in a week outside the weeks the plan can show goes to its nearest edge; a date in one of those
     * weeks is shown as it is. Without a plan, any day.
     */
    public function insidePlan(DateTimeImmutable $day): DateTimeImmutable
    {
        $lower = $this->firstDay();
        $upper = $this->browseEnd();
        if ($lower !== null && $this->weekStart($day) < $this->weekStart($lower)) {
            return $lower;
        }

        return $upper !== null && $this->weekStart($day) > $this->weekStart($upper) ? $upper : $day;
    }

    /**
     * SCH-020: the same weekday `$days` later or earlier, kept inside the plan (rp-clone weekShifted); null when that
     * week is outside it.
     */
    public function weekShifted(DateTimeImmutable $day, int $days): ?DateTimeImmutable
    {
        $target = $day->modify(sprintf('%+d days', $days));
        $start = $this->weekStart($target);
        $lower = $this->firstDay();
        $upper = $this->lastDay();
        if (($lower !== null && $start < $this->weekStart($lower)) || ($upper !== null && $start > $upper)) {
            return null;
        }

        return max($lower ?? $target, min($upper ?? $target, $target));
    }

    // MARK: sessions (SCH-040)

    /**
     * What the plan puts on a day (PLAN-008, PLAN-022, SCH-040, SCH-060), from the one Domain rule
     * {@see WeekSessions}; null before the plan starts.
     *
     * @return ?array{plan: DaySessionPlan, mesocycle: ?Mesocycle}
     */
    public function planning(DateTimeImmutable $day): ?array
    {
        $key = $day->format('Y-m-d');
        if (! array_key_exists($key, $this->planning)) {
            $this->planning[$key] = $this->plan($day);
        }

        return $this->planning[$key];
    }

    /** @return ?array{plan: DaySessionPlan, mesocycle: ?Mesocycle} */
    private function plan(DateTimeImmutable $day): ?array
    {
        $grid = $this->train->grid();
        $profile = $this->train->profile;
        if ($grid === null || $profile === null) {
            return null;
        }
        $week = $grid->weekIndexContaining($day);
        if ($week === null) {
            return null;
        }
        $flagged = $this->train->planWeekRow($grid->start($week));
        $paused = $this->pausedWeeks();
        $saved = $this->train->saved()->values();
        $covering = $this->coveringIn($week);
        $mesocycle = $covering === null ? null : $saved[$covering->index];

        $records = $this->train->sessions
            ->filter(fn (WorkoutSession $s) => $mesocycle !== null && $s->mesocycle_id === $mesocycle->id)
            ->map(fn (WorkoutSession $s) => new WorkoutSessionRecord(
                id: (string) $s->id,
                mesocycleId: $s->mesocycle_id,
                week: $s->week,
                day: $s->day,
                isSkipped: $s->is_skipped,
                plannedStartAt: self::instant($s->planned_start_at),
                actualStartAt: self::instant($s->actual_start_at),
            ))->values()->all();
        $logged = array_values(array_map(
            fn (WorkoutSessionRecord $r) => new LoggedDay($r->week, (int) $r->day),
            array_filter($records, fn (WorkoutSessionRecord $r) => $r->actualStartAt !== null && $r->day !== null),
        ));

        $plan = WeekSessions::plan(
            on: $day,
            grid: $grid,
            mesocycle: $mesocycle === null ? null : $this->domain($mesocycle),
            mesocycleStartWeek: $covering->placed->startWeek ?? 0,
            trainingDays: $this->trainingDays(),
            awayTraining: $flagged?->is_away ? Training::tryFrom((string) $flagged->away_training) : null,
            flaggedDeload: (bool) ($flagged->is_deload ?? false),
            logged: $logged,
            pausedWeeks: $paused,
            rows: SessionRow::rows($records, $mesocycle?->id),
        );

        return $plan === null ? null : ['plan' => $plan, 'mesocycle' => $mesocycle];
    }

    /** PLAN-002, SCH-060: the saved mesocycle the plan week of a day belongs to. */
    public function covering(DateTimeImmutable $day): ?Mesocycle
    {
        $week = $this->train->grid()?->weekIndexContaining($day);
        $covering = $week === null ? null : $this->coveringIn($week);

        return $covering === null ? null : $this->train->saved()->values()[$covering->index];
    }

    private function coveringIn(int $week): ?CoveringMesocycle
    {
        $grid = $this->train->grid();

        return $grid === null ? null : PlanRules::coveringMesocycle(
            $week,
            $this->train->saved()->values()->map(fn (Mesocycle $m) => new DatedMesocycle(TrainSnapshot::day($m->start_date), (int) $m->weeks))->all(),
            $grid,
            $this->pausedWeeks(),
        );
    }

    /** The mesocycle as the Domain reads it, with the last day MESO-043 cuts its sessions at. */
    private function domain(Mesocycle $mesocycle): DomainMesocycle
    {
        $domain = $this->train->domain($mesocycle);

        return new DomainMesocycle(
            id: $domain->id,
            phase: $domain->phase,
            weeks: $domain->weeks,
            sessions: $domain->sessions,
            musclePriorities: $domain->musclePriorities,
            isResensitization: $domain->isResensitization,
            earlierWeeks: $domain->earlierWeeks,
            laterWeeks: $domain->laterWeeks,
            lastDay: $mesocycle->ended_on === null ? null : $this->train->calendar($mesocycle)->lastDay(),
        );
    }

    /**
     * SCH-060: the plan weeks an away week pauses.
     *
     * @return list<int>
     */
    private function pausedWeeks(): array
    {
        $grid = $this->train->grid();
        if ($grid === null) {
            return [];
        }

        return $this->train->planWeeks
            ->filter(fn (PlanWeek $w) => $w->is_away && $w->away_training === Training::Pause->value)
            ->map(fn (PlanWeek $w) => $grid->weekIndexContaining(TrainSnapshot::day($w->start_date)))
            ->filter(fn (?int $index) => $index !== null)
            ->values()->all();
    }

    /** @return list<TrainingDay> the profile's training days, in the Domain's weekdays (1 is Sunday) */
    public function trainingDays(): array
    {
        $days = [];
        foreach (self::json($this->train->profile?->training_days) as $day) {
            $start = ClockTime::fromHhmm((string) ($day['start'] ?? ''));
            if ($start !== null) {
                $days[] = new TrainingDay(
                    PlanWeekDays::calendarWeekdayFromIso((int) $day['weekday']),
                    new WorkoutTime($start, (int) ($day['minutes'] ?? 60)),
                );
            }
        }

        return $days;
    }

    /** SCH-003, SCH-040, SCH-046: the mesocycle session on a day, from the mesocycle's first day on. */
    public function workout(DateTimeImmutable $day): ?TodayWorkoutItem
    {
        $found = $this->planning($day);
        $mesocycle = $found['mesocycle'] ?? null;
        $plan = $found['plan'] ?? null;
        if ($mesocycle === null || $plan?->session === null || $plan->sessionNumber === null || $plan->mesocycleWeek === null
            || $day < TrainSnapshot::day($mesocycle->start_date)) {
            return null;
        }
        $week = $plan->mesocycleWeek + 1;
        $number = $plan->sessionNumber;
        $placed = $plan->session;
        $mine = $this->train->sessions->filter(fn (WorkoutSession $s) => $s->mesocycle_id === $mesocycle->id && $s->day === $number);
        $past = $mine->filter(fn (WorkoutSession $s) => $s->week < $week && $s->actual_start_at !== null && $s->actual_end_at !== null)
            ->sortBy('week')
            ->map(fn (WorkoutSession $s) => (int) round((self::instant($s->actual_end_at)->getTimestamp() - self::instant($s->actual_start_at)->getTimestamp()) / 60))
            ->values()->all();
        $row = $mine->first(fn (WorkoutSession $s) => $s->week === $week);
        $workingSets = array_sum(array_map(fn ($slot) => $slot->sets, $placed->session->slots));
        $done = $row !== null && ! $row->is_skipped && $row->actual_end_at !== null;

        return new TodayWorkoutItem(
            date: $day,
            mesocycle: $mesocycle,
            week: $week,
            day: $number,
            session: $placed->session,
            time: $placed->time->start,
            minutes: EnergyRules::sessionMinutes($workingSets, $past),
            state: match (true) {
                $placed->isSkipped => TodayWorkoutItem::SKIPPED,
                $done => TodayWorkoutItem::DONE,
                $row?->actual_start_at !== null => TodayWorkoutItem::STARTED,
                default => TodayWorkoutItem::PLANNED,
            },
            setsCompleted: $done ? $this->train->loggedSets($row)->count() : null,
        );
    }

    /** SCH-050, SCH-051: a session not yet started or skipped can be re-timed or moved, from today on. */
    public function isMovable(TodayWorkoutItem $workout): bool
    {
        return ! $workout->isLogged() && $workout->date >= $this->today();
    }

    /**
     * SCH-038, SCH-051, SCH-057: the days a session on `$from` could move to: the other days of its plan week from
     * today on, each with whether it already holds a session (and so is refused). Never another plan week.
     *
     * @return list<array{date: DateTimeImmutable, taken: bool}>
     */
    public function moveDays(DateTimeImmutable $from): array
    {
        $days = [];
        foreach ($this->planWeekDays($from) as $day) {
            if ($day != $from && $day >= $this->today()) {
                $days[] = ['date' => $day, 'taken' => $this->workout($day) !== null];
            }
        }

        return $days;
    }

    /**
     * SCH-032, SCH-038: the session Add workout moves onto `$onto`: the next one of its plan week, from today on, not
     * yet started or skipped. None when `$onto` already holds one.
     */
    public function nextMovableSession(DateTimeImmutable $onto): ?TodayWorkoutItem
    {
        if ($this->workout($onto) !== null) {
            return null;
        }
        foreach ($this->planWeekDays($onto) as $day) {
            $candidate = $day > $onto ? $this->workout($day) : null;
            if ($candidate !== null && $this->isMovable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<DateTimeImmutable> the days of the plan week a day falls in; none before the plan */
    private function planWeekDays(DateTimeImmutable $day): array
    {
        $grid = $this->train->grid();
        $week = $grid?->weekIndexContaining($day);

        return $week === null ? [] : $grid->planWeek($week)->days();
    }

    /** PLAN-022, SCH-060: the day's plan week is a deload. */
    public function isDeload(DateTimeImmutable $day): bool
    {
        return $this->planning($day)['plan']->isDeload ?? false;
    }

    /** SCH-060: the day's plan week is marked away. */
    public function isAway(DateTimeImmutable $day): bool
    {
        $grid = $this->train->grid();
        $week = $grid?->weekIndexContaining($day);

        return $week !== null && (bool) ($this->train->planWeekRow($grid->start($week))->is_away ?? false);
    }

    // MARK: meals (SCH-002)

    public function dayTarget(DateTimeImmutable $day): ?DayTarget
    {
        return $this->dayTargets->first(fn (DayTarget $t) => TrainSnapshot::day($t->date) == $day);
    }

    /**
     * SCH-002: the day target's meals joined by id with what was planned and eaten, in the stored order.
     *
     * @return list<TodayMealItem>
     */
    public function meals(DateTimeImmutable $day): array
    {
        $target = $this->dayTarget($day);
        if ($target === null) {
            return [];
        }
        $index = $this->meals->keyBy(fn (DayTargetMeal $m) => strtolower((string) $m->id));
        $items = [];
        foreach (self::json($target->meals) as $position => $meal) {
            $row = $index->get(strtolower((string) ($meal['id'] ?? '')));
            $checkIn = $row?->checkIn;
            $items[] = new TodayMealItem(
                id: (string) ($meal['id'] ?? ''),
                name: ($meal['name'] ?? null) ?: 'Meal '.($position + 1),
                time: isset($meal['time']) ? ClockTime::fromHhmm((string) $meal['time']) : null,
                target: new MealTarget(
                    kcal: (float) ($meal['kcal'] ?? 0),
                    proteinG: (float) ($meal['protein_g'] ?? 0),
                    fatG: (float) ($meal['fat_g'] ?? 0),
                    carbsG: (float) ($meal['carbs_g'] ?? 0),
                ),
                foods: $row === null ? [] : $row->plannedFoods->map(fn (PlannedFood $f) => self::food($f))->values()->all(),
                recipes: $row === null ? [] : $row->plannedRecipes->map(fn (PlannedRecipe $r) => self::recipe($r))->values()->all(),
                eaten: $checkIn === null ? null : new MealTarget(
                    kcal: $checkIn->kcal, proteinG: $checkIn->protein_g, fatG: $checkIn->fat_g, carbsG: $checkIn->carbs_g,
                ),
                eatenTime: $checkIn?->time,
                planningState: $row?->planningState?->state,
            );
        }

        return $items;
    }

    private static function food(PlannedFood $planned): TodayPlannedEntry
    {
        $food = $planned->food;
        $macros = $food === null ? new MealTarget(0, 0, 0, 0) : (new MealFood(
            id: (string) $planned->id,
            food: self::mealFitFood($food),
            grams: $planned->grams,
            weighing: $planned->is_cooked ? Weighing::Cooked : Weighing::Raw,
        ))->macros();

        return new TodayPlannedEntry($food->name ?? 'Food', self::number($planned->grams).' g', $macros, $planned->position);
    }

    /** RCP-002: servings of a recipe, at its ingredients' macros per serving. */
    private static function recipe(PlannedRecipe $planned): TodayPlannedEntry
    {
        $recipe = $planned->sourceRecipe;
        $perServing = $recipe === null ? new MealTarget(0, 0, 0, 0) : RecipeMacros::perServing(
            $recipe->ingredients->map(fn (RecipeIngredient $i) => new MealFood(
                id: (string) $i->id,
                food: self::mealFitFood($i->food),
                grams: (float) $i->grams,
                weighing: $i->is_cooked ? Weighing::Cooked : Weighing::Raw,
            ))->values()->all(),
            (float) $recipe->servings,
        );
        $servings = $planned->servings;

        return new TodayPlannedEntry(
            (string) (self::json($planned->recipe)['name'] ?? $recipe->name ?? 'Recipe'),
            self::number($servings).($servings == 1.0 ? ' serving' : ' servings'),
            new MealTarget(
                kcal: $perServing->kcal * $servings,
                proteinG: $perServing->proteinG * $servings,
                fatG: $perServing->fatG * $servings,
                carbsG: $perServing->carbsG * $servings,
            ),
            $planned->position,
        );
    }

    private static function mealFitFood(Food $food): MealFitFood
    {
        return new MealFitFood(
            id: (string) $food->id,
            name: $food->name,
            kcal: $food->kcal,
            proteinG: $food->protein,
            fatG: $food->fat,
            carbsG: $food->carbs,
            cookedRatio: $food->cooked_ratio,
        );
    }

    /**
     * A JSON column's value; nothing when it holds none.
     *
     * @return array<array-key, mixed>
     */
    public static function json(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** A stored time as an instant; null when there is none. */
    public static function instant(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toDateTimeImmutable();
    }

    /** "80", "1.5". */
    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    // MARK: consumed (SCH-006, SCH-021)

    /**
     * What was eaten on a day: its check-ins and its off-plan entries (DAY-001); null when there are none. The green
     * dot reads this, never a planned meal.
     */
    public function consumed(DateTimeImmutable $day): ?DayTotals
    {
        $eaten = array_values(array_filter(array_map(fn (TodayMealItem $m) => $m->eaten, $this->meals($day))));
        $offPlan = $this->offPlan($day);
        if ($eaten === [] && $offPlan === []) {
            return null;
        }

        return new DayTotals(
            kcal: array_sum(array_map(fn (MealTarget $m) => $m->kcal, $eaten)) + array_sum(array_map(fn (OffPlanEntry $e) => $e->kcal, $offPlan)),
            proteinG: array_sum(array_map(fn (MealTarget $m) => $m->proteinG, $eaten)) + array_sum(array_map(fn (OffPlanEntry $e) => $e->protein_g, $offPlan)),
        );
    }

    /**
     * DAY-011, SET-003: the totals bar's consumed. What was eaten, and with the default setting every meal not checked
     * in at what a one-tap check-in would record.
     */
    public function consumedForTotals(DateTimeImmutable $day): DayTotals
    {
        $total = $this->consumed($day) ?? new DayTotals(0, 0);
        if (($this->train->profile->consumed_totals ?? 'planned') !== 'planned') {
            return $total;
        }
        $kcal = $total->kcal;
        $protein = $total->proteinG;
        foreach ($this->meals($day) as $meal) {
            if (! $meal->isChecked()) {
                $kcal += $meal->plannedCheckIn()->kcal;
                $protein += $meal->plannedCheckIn()->proteinG;
            }
        }

        return new DayTotals($kcal, $protein);
    }

    /** SCH-021, DAY-010: a finished day before today whose consumed calories and protein were on target. */
    public function isComplete(DateTimeImmutable $day): bool
    {
        $start = $this->planStart();
        $target = $this->dayTarget($day);
        if ($start === null) {
            return false;
        }

        return Adherence::status(
            day: $day,
            today: $this->today(),
            planStart: $start,
            target: $target === null ? null : new DayTotals($target->kcal, $target->protein_g),
            consumed: $this->consumed($day),
            zone: new DateTimeZone('UTC'),
        ) === DayAdherence::OnTrack;
    }

    /** @return list<OffPlanEntry> */
    public function offPlan(DateTimeImmutable $day): array
    {
        return $this->offPlan->filter(fn (OffPlanEntry $e) => TrainSnapshot::day($e->date) == $day)->values()->all();
    }

    /** SCH-032: the day's stored Other activity, at most one. */
    public function otherActivity(DateTimeImmutable $day): ?OtherActivity
    {
        return $this->otherActivities->first(fn (OtherActivity $a) => TrainSnapshot::day($a->date) == $day);
    }
}
