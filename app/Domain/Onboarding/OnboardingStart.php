<?php

namespace App\Domain\Onboarding;

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use App\Domain\Placement\ClockTime;
use App\Domain\Safety\ScreeningItem;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;
use Closure;
use DateTimeImmutable;
use DomainException;

/**
 * "Start my plan" (ONB-020), rp-clone's OnboardingModel+Start: the rows the answers save, as the reads take them.
 * Every visible step must be able to continue; the plan's first week is built by the rules, moved by a chosen calorie
 * average (ONB-017); a start date that has passed moves to tomorrow and keeps its length. A training-only setup
 * (ONB-005, SAFE-003) saves the defaults of the steps it skipped and no day targets.
 *
 * Weekdays are ISO (1 = Monday); times are "HH:MM"; dates are Y-m-d.
 */
final readonly class OnboardingStart
{
    public const INCOMPLETE_MESSAGE = 'Some answers are missing. Go back through the steps and check each one.';

    /** The name of a first mesocycle that has no template to take one from. */
    public const DRAFT_MESOCYCLE_NAME = 'First mesocycle';

    /**
     * @param  array<string, mixed>  $profile  the profiles row
     * @param  array{start_date: string, review_weekday: int}  $plan
     * @param  array<string, mixed>  $phase  the first phase
     * @param  array<string, mixed>  $mesocycle  the first mesocycle, its days as `Mesocycle::$days` holds them
     * @param  array<string, mixed>  $planWeek  plan week 0
     * @param  list<array<string, mixed>>  $dayTargets  week 0's days, each with its meals
     * @param  list<array{weekdays: list<int>, time: string}>  $weighInSchedule
     * @param  list<string>  $weekDates  every day of plan week 0, targets or not
     */
    public function __construct(
        public array $profile,
        public array $plan,
        public array $phase,
        public array $mesocycle,
        public array $planWeek,
        public array $dayTargets,
        public array $weighInSchedule,
        public array $weekDates,
    ) {}

    /**
     * @param  list<OnboardingTemplate>  $templates  the library, as step 9 reads it
     * @param  Closure(): string  $newId  an id for the phase, the mesocycle, each day, slot, plan week, day target and meal
     *
     * @throws DomainException in the words of the step that cannot continue, or of the rules that refuse the week
     */
    public static function from(Draft $draft, DateTimeImmutable $today, array $templates, Closure $newId): self
    {
        $draft = self::startingTomorrowAtTheEarliest($draft, $today);
        self::refuseUnlessEveryStepCanContinue($draft, $today, $templates);
        $inputs = OnboardingPlanInputs::from($draft, $today, $templates, $newId) ?? throw new DomainException(self::INCOMPLETE_MESSAGE);
        $trainingOnly = $inputs->schedule->nutrition === null;
        $calories = new OnboardingCalories($draft, $today, $templates);

        // ONB-017: a chosen average moves week one by its difference from the recommendation.
        $change = 0.0;
        if (! $trainingOnly) {
            $recommended = $calories->recommended();
            if ($recommended === null || ! $calories->isReady()) {
                throw new DomainException($calories->message() ?? self::INCOMPLETE_MESSAGE);
            }
            if ($draft->choosesOwnCalories && $draft->ownDailyKcal !== null) {
                $change = $draft->ownDailyKcal - $recommended->averageKcal;
            }
        }
        try {
            $built = $inputs->buildWeek(0, $change, $newId);
        } catch (WeekBuildRefusal $refusal) {
            throw new DomainException($calories->messageFor($refusal));
        }

        $phase = $inputs->phase();
        if ($phase->end === null) {
            throw new DomainException(self::INCOMPLETE_MESSAGE);
        }
        $start = $inputs->plan->start->format('Y-m-d');

        return new self(
            profile: self::profile($draft, $today, $inputs, $trainingOnly),
            plan: ['start_date' => $start, 'review_weekday' => (new OnboardingLifestyle($draft))->reviewWeekday()],
            phase: [
                'id' => $phase->id,
                'type' => $phase->type->value,
                'start_date' => $start,
                'end_date' => $phase->end->format('Y-m-d'),
                'target_rate_percent_per_week' => $phase->targetRatePercentPerWeek,
            ],
            mesocycle: self::mesocycle($inputs, $draft, $start),
            planWeek: [
                'id' => $newId(),
                'start_date' => $built->week->start->format('Y-m-d'),
                'is_deload' => $built->week->isDeload,
                'is_diet_break' => $built->week->isDietBreak,
                'is_away' => $built->week->isAway,
            ],
            dayTargets: $trainingOnly ? [] : self::dayTargets($built, $newId),
            weighInSchedule: self::weighInSchedule($draft->weighIns),
            weekDates: array_map(fn (WeekDay $day) => $day->day->date->format('Y-m-d'), $built->days),
        );
    }

    /** ONB-020: the plan starts tomorrow at the earliest. An earlier start moves to tomorrow; the length in weeks stays. */
    public static function startingTomorrowAtTheEarliest(Draft $draft, DateTimeImmutable $today): Draft
    {
        $tomorrow = OnboardingDates::tomorrow($today);

        return $draft->startDate !== null && OnboardingDates::day($draft->startDate) < $tomorrow
            ? $draft->with(startDate: $tomorrow)
            : $draft;
    }

    /**
     * WT-001: weekdays that share a time share an entry, in the order of their first weekday.
     *
     * @param  list<WeighInChoice>  $weighIns
     * @return list<array{weekdays: list<int>, time: string}>
     */
    public static function weighInSchedule(array $weighIns): array
    {
        $byTime = [];
        foreach ($weighIns as $weighIn) {
            $byTime[$weighIn->minutes][] = $weighIn->weekday;
        }
        $entries = [];
        foreach ($byTime as $minutes => $weekdays) {
            sort($weekdays);
            $entries[] = ['weekdays' => $weekdays, 'time' => (string) new ClockTime($minutes)];
        }
        usort($entries, fn (array $a, array $b) => [$a['weekdays'][0], $a['time']] <=> [$b['weekdays'][0], $b['time']]);

        return $entries;
    }

    /** @param  list<OnboardingTemplate>  $templates */
    private static function refuseUnlessEveryStepCanContinue(Draft $draft, DateTimeImmutable $today, array $templates): void
    {
        $steps = new OnboardingSteps($draft->with(step: OnboardingSteps::REVIEW_STEP), $today, $templates);
        foreach ($steps->visibleSteps() as $step) {
            if (! $steps->canContinueOn($step)) {
                throw new DomainException($steps->continueHintOn($step) ?? self::INCOMPLETE_MESSAGE);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function profile(Draft $draft, DateTimeImmutable $today, OnboardingPlanInputs $inputs, bool $trainingOnly): array
    {
        // ONB-005: a training-only user skipped steps 4, 5, 10 and 11, so those are saved as their defaults.
        $lifestyle = new OnboardingLifestyle($trainingOnly ? new Draft : $draft);
        $window = $lifestyle->eatingWindow();
        // SAFE-001: the items that applied, ticked or measured, in the spec's order.
        $applied = [...$draft->screening, ...(new OnboardingHealth($draft, $today))->measuredItems()];
        $profile = $inputs->profile;

        return [
            'name' => $profile->name,
            'sex' => $profile->sex->value,
            'birth_date' => $profile->birthDate->format('Y-m-d'),
            'height_cm' => $profile->heightCm,
            'weight_kg' => $profile->weightKg,
            'body_fat_bucket' => $trainingOnly ? null : $draft->bodyFatBucket?->value,
            'uses_glp1' => $trainingOnly ? null : $draft->usesGLP1,
            'experience' => $profile->experience->value,
            'weight_unit' => $profile->weightUnit->value,
            'height_unit' => $profile->heightUnit->value,
            'appearance' => 'system',
            'consumed_totals' => 'planned',
            'eating_window' => ['start' => (string) $window->start, 'end' => (string) $window->end],
            'meal_count' => $trainingOnly ? (new Draft)->mealCount : $draft->mealCount,
            'busy_periods' => $trainingOnly ? [] : array_map(fn (BusyPeriodChoice $period) => [
                'label' => $period->label,
                'start' => (string) new ClockTime($period->startMinutes),
                'end' => (string) new ClockTime($period->endMinutes),
                'weekdays' => $period->weekdays,
            ], $draft->busyPeriods),
            'training_days' => array_map(fn (TrainingDayChoice $day) => [
                'weekday' => $day->weekday, 'start' => (string) new ClockTime($day->startMinutes), 'minutes' => $day->lengthMinutes,
            ], $draft->trainingDays),
            'weigh_in_days' => array_map(fn (WeighInChoice $weighIn) => [
                'weekday' => $weighIn->weekday, 'time' => (string) new ClockTime($weighIn->minutes),
            ], $draft->weighIns),
            'step_plan' => array_map(fn (StepRangeChoice $range) => [
                'weekday' => $range->weekday, 'min' => $range->min, 'max' => $range->max,
            ], $lifestyle->stepRanges()),
            'diet_filters' => [],
            'screening' => array_values(array_map(
                fn (ScreeningItem $item) => $item->value,
                array_filter(ScreeningItem::cases(), fn (ScreeningItem $item) => in_array($item, $applied, true)),
            )),
            'training_only' => $trainingOnly,
        ];
    }

    /**
     * The template's sessions with their exercises, or empty sessions for a draft that the Meso Builder or "build it
     * later" finishes (ONB-012, MESO-007).
     *
     * @return array<string, mixed>
     */
    private static function mesocycle(OnboardingPlanInputs $inputs, Draft $draft, string $start): array
    {
        $template = $inputs->template;
        $days = array_map(fn (int $index, Session $session) => [
            'id' => $session->id,
            'label' => $session->name,
            'weekday' => $inputs->sessionWeekdays[$index] ?? null,
            'slots' => $template === null ? [] : array_map(fn (int $position, ExerciseSlot $slot) => [
                'id' => $slot->id,
                'exercise_id' => $template->days[$index]->slots[$position]->exerciseId ?? null,
                'muscle_group' => $slot->muscle->value,
                'sets' => $slot->sets,
                'carried' => null,
            ], array_keys($session->slots), $session->slots),
        ], array_keys($inputs->mesocycle->sessions), $inputs->mesocycle->sessions);

        // MESO-012: the template's priorities; MESO-003: the phase's preset when it has none. A draft leaves them to
        // the build.
        $priorities = $inputs->mesocycle->musclePriorities;
        if ($template !== null && $priorities === []) {
            $priorities = MesoBuilder::defaultPriorities(Column::for($inputs->mesocycle->phase, false));
        }

        return [
            'id' => $inputs->mesocycle->id,
            'phase_id' => $inputs->phase()->id,
            'name' => $template->name ?? self::DRAFT_MESOCYCLE_NAME,
            'weeks' => $inputs->mesocycle->weeks,
            'start_date' => $start,
            'is_draft' => $template === null,
            'is_resensitization' => false,
            'route' => match (true) {
                $template !== null => 'template',
                $draft->trainingPlan === TrainingPlanKind::MesoBuilder => 'builder',
                default => 'scratch',
            },
            'days' => $days,
            'muscle_priorities' => $template === null ? null : array_map(fn (Priority $priority) => $priority->value, $priorities),
        ];
    }

    /**
     * Week one's days with their totals and meals, each with a new id.
     *
     * @param  Closure(): string  $newId
     * @return list<array<string, mixed>>
     */
    private static function dayTargets(BuiltWeek $built, Closure $newId): array
    {
        $days = array_filter($built->days, fn (WeekDay $day) => $day->target !== null);

        return array_values(array_map(fn (WeekDay $day) => [
            'id' => $newId(),
            'date' => $day->day->date->format('Y-m-d'),
            'day_type' => $day->day->type->value,
            'kcal' => $day->target->kcal,
            'protein_g' => $day->target->proteinG,
            'fat_g' => $day->target->fatG,
            'carbs_g' => $day->target->carbsG,
            'meals' => array_map(fn (WeekMeal $meal) => [
                'id' => $meal->id(),
                'name' => $meal->meal->name,
                'time' => (string) $meal->time,
                'kcal' => $meal->meal->target->kcal,
                'protein_g' => $meal->meal->target->proteinG,
                'fat_g' => $meal->meal->target->fatG,
                'carbs_g' => $meal->meal->target->carbsG,
            ], $day->meals),
        ], $days));
    }
}
