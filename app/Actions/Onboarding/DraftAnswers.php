<?php

namespace App\Actions\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingAnswers;
use App\Capabilities\Onboarding\Data\OnboardingBusyPeriodAnswer;
use App\Capabilities\Onboarding\Data\OnboardingHeightAnswer;
use App\Capabilities\Onboarding\Data\OnboardingScreeningAnswer;
use App\Capabilities\Onboarding\Data\OnboardingStepRangeAnswer;
use App\Capabilities\Onboarding\Data\OnboardingTrainingDayAnswer;
use App\Capabilities\Onboarding\Data\OnboardingWeighInAnswer;
use App\Capabilities\Onboarding\Data\OnboardingWeightAnswer;
use App\Capabilities\Support\FieldRefused;
use App\Domain\Defaults\TBL010Phases;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingCalories;
use App\Domain\Onboarding\OnboardingDates;
use App\Domain\Onboarding\OnboardingHealth;
use App\Domain\Onboarding\OnboardingLifestyle;
use App\Domain\Onboarding\OnboardingRateLength;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Domain\Onboarding\OnboardingText;
use App\Domain\Onboarding\OnboardingTraining;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\TemplateOption;
use App\Domain\Onboarding\TrainingDayChoice;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Safety\Screening;
use App\Domain\Safety\ScreeningItem;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The mutating members of rp-clone's OnboardingModel, one per answer key: each sets its answer and what it changes.
 * A key left out (null) keeps the saved answer. Answers apply in step order, so a goal resets step 6 before a rate
 * sent with it, and a template is checked against training days sent with it. What a value is worked out from (the
 * defaults, the limits, the options a step offers) is read from `App\Domain\Onboarding`.
 */
final class DraftAnswers
{
    /** What a new busy period with no label is called, as rp-clone's addBusyPeriod. */
    public const BUSY_LABEL = 'Busy';

    public const GOAL_NOT_ALLOWED = OnboardingHealth::FAT_LOSS_BLOCKED_NOTICE;

    public const RATE_NOT_OFFERED = 'Pick one of the rates step 6 offers.';

    public const PLAN_NOT_OFFERED = 'Pick one of the training plans step 9 offers.';

    public const BUSY_PERIOD_NEEDS_A_DAY = 'Pick at least one weekday for each busy period.';

    private const TEMPLATE_PREFIX = 'template:';

    /**
     * @param  Closure(Draft): list<OnboardingTemplate>  $templates
     * @param  Closure(): string  $newId
     */
    public static function apply(Draft $draft, OnboardingAnswers $answers, DateTimeImmutable $today, Closure $templates, Closure $newId): Draft
    {
        $a = $answers;
        $d = $draft;

        // Step 1 (ONB-003, ONB-021)
        if ($a->goal !== null) {
            $d = self::selectGoal($d, PhaseType::from($a->goal), $today);
        }
        if ($a->finished_diet_recently !== null) {
            $d = $d->with(finishedDietRecently: $a->finished_diet_recently);
        }

        // Step 2 (ONB-004)
        if ($a->name !== null) {
            $d = $d->with(name: $a->name);
        }
        if ($a->sex !== null) {
            $d = $d->with(sex: Sex::from($a->sex));
        }
        if ($a->birth_date !== null) {
            $d = $d->with(birthDate: self::day($a->birth_date));
        }
        if ($a->height !== null) {
            $d = self::height($d, $a->height);
        }
        if ($a->weight !== null) {
            $d = self::weight($d, $a->weight);
        }

        // Steps 3 to 5 (SAFE-001, ONB-006, SAFE-013, ONB-007)
        if ($a->screening !== null) {
            $d = self::screening($d, $a->screening);
        }
        if ($a->uses_glp1 !== null) {
            $d = $d->with(usesGLP1: $a->uses_glp1);
        }
        if ($a->body_fat !== null) {
            $d = $d->with(bodyFatBucket: BodyFatBucket::from($a->body_fat));
        }

        // Step 6 (ONB-008, ONB-009)
        if ($a->rate_option !== null) {
            $d = self::selectRateOption($d, $a->rate_option, $today);
        }
        if ($a->custom_rate !== null) {
            $d = self::customRate($d, $a->custom_rate);
        }
        if ($a->weeks !== null) {
            $d = $d->with(weeks: self::clampedWeeks($d, $a->weeks));
        }
        if ($a->start_date !== null) {
            $d = $d->with(startDate: self::day($a->start_date));
        }
        if ($a->last_day !== null) {
            $d = self::lastDay($d, self::day($a->last_day));
        }
        if ($a->target_weight !== null) {
            $d = self::targetWeight($d, $a->target_weight, $today);
        }

        // Steps 7 to 9 (ONB-010 to ONB-012)
        if ($a->experience !== null) {
            $d = $d->with(experience: ExperienceLevel::from($a->experience));
        }
        if ($a->training_days !== null) {
            $d = self::trainingDays($d, $a->training_days);
        }
        if ($a->training_plan !== null) {
            $d = self::trainingPlan($d, $a->training_plan, $templates($d));
        }

        // Steps 10 to 12 (ONB-013 to ONB-016)
        if ($a->step_ranges !== null) {
            $d = self::stepRanges($a->step_ranges, $d);
        }
        if ($a->first_meal_minutes !== null) {
            $d = $d->with(eatingStartMinutes: self::minuteOfDay($a->first_meal_minutes));
        }
        if ($a->last_meal_minutes !== null) {
            $d = $d->with(eatingEndMinutes: self::minuteOfDay($a->last_meal_minutes));
        }
        if ($a->meal_count !== null) {
            $d = $d->with(mealCount: self::clamp($a->meal_count, OnboardingLifestyle::MINIMUM_MEALS, OnboardingLifestyle::MAXIMUM_MEALS));
        }
        if ($a->busy_periods !== null) {
            $d = self::busyPeriods($d, $a->busy_periods, $newId);
        }
        if ($a->weigh_ins !== null) {
            $d = self::weighIns($d, $a->weigh_ins);
        }
        if ($a->review_weekday !== null) {
            $d = $d->with(reviewWeekday: (int) $a->review_weekday);
        }

        // Step 13 (ONB-017)
        if ($a->calories !== null) {
            $d = self::calories($d, $a->calories === 'own', $today, $templates);
        }
        if ($a->own_kcal !== null) {
            $d = $d->with(ownDailyKcal: min($a->own_kcal, OnboardingCalories::MAXIMUM_OWN_DAILY_KCAL));
        }

        return $d;
    }

    /** selectGoal: a different goal puts step 6 back to that goal's defaults (applyRateDefaults). */
    private static function selectGoal(Draft $draft, PhaseType $goal, DateTimeImmutable $today): Draft
    {
        if (! (new OnboardingHealth($draft, $today))->isGoalAllowed($goal)) {
            throw new FieldRefused('answers.goal', self::GOAL_NOT_ALLOWED);
        }
        if ($draft->goal === $goal) {
            return $draft;
        }

        return $draft->with(
            goal: $goal,
            ratePercentPerWeek: OnboardingRateLength::defaultRate($goal),
            customRate: false,
            weeks: OnboardingRateLength::defaultWeeks($goal),
            startDate: $draft->startDate ?? OnboardingDates::tomorrow($today),
        );
    }

    /** The height in the unit on screen, stored in cm. A unit with no number only switches the unit. */
    private static function height(Draft $draft, OnboardingHeightAnswer $height): Draft
    {
        $unit = HeightUnit::from($height->unit);
        $cm = match ($unit) {
            HeightUnit::Cm => $height->cm,
            HeightUnit::FeetInches => $height->feet === null && $height->inches === null
                ? null
                : UnitConversion::feetInchesToCm($height->feet ?? 0, $height->inches ?? 0.0),
        };

        return $draft->with(heightUnit: $unit, heightCm: $cm ?? $draft->heightCm);
    }

    /** The weight in the unit on screen, stored in kg. A unit with no value only switches the unit. */
    private static function weight(Draft $draft, OnboardingWeightAnswer $weight): Draft
    {
        $unit = WeightUnit::from($weight->unit);

        return $draft->with(
            weightUnit: $unit,
            weightKg: $weight->value === null ? $draft->weightKg : OnboardingText::kgFrom($weight->value, $unit),
        );
    }

    /**
     * toggleScreening: sets each item named. An item measured from step 2 is never stored, so it stays on while the
     * answers on step 2 measure it (SAFE-001).
     *
     * @param  list<OnboardingScreeningAnswer>  $answers
     */
    private static function screening(Draft $draft, array $answers): Draft
    {
        $on = array_fill_keys(array_map(fn (ScreeningItem $item) => $item->value, $draft->screening), true);
        foreach ($answers as $answer) {
            $on[$answer->item] = $answer->is_on;
        }

        return $draft->with(screening: array_values(array_filter(
            ScreeningItem::cases(),
            fn (ScreeningItem $item) => ($on[$item->value] ?? false) && ! in_array($item, Screening::MEASURED_KINDS, true),
        )));
    }

    /** selectRateOption: a preset sets its rate; Custom keeps the rate the user is looking at. */
    private static function selectRateOption(Draft $draft, string $name, DateTimeImmutable $today): Draft
    {
        foreach ((new OnboardingRateLength($draft, $today))->rateOptions() as $option) {
            if ($option->name === $name) {
                return $option->ratePercentPerWeek === null
                    ? $draft->with(customRate: true)
                    : $draft->with(ratePercentPerWeek: $option->ratePercentPerWeek, customRate: false);
            }
        }
        throw new FieldRefused('answers.rate_option', self::RATE_NOT_OFFERED);
    }

    /**
     * setCustomRate: the rate as the slider shows it, positive for fat loss, clamped to the goal's TBL-010 range, so
     * fat loss never goes beyond 1% a week (SAFE-005). With no goal there is no range, and nothing changes.
     */
    private static function customRate(Draft $draft, float $slider): Draft
    {
        if ($draft->goal === null) {
            return $draft;
        }
        $range = TBL010Phases::row($draft->goal)->rateRangePercentPerWeek;
        $rate = $slider * ($draft->goal === PhaseType::FatLoss ? -1 : 1);

        return $draft->with(ratePercentPerWeek: min(max($rate, $range->lower), $range->upper) + 0.0, customRate: true);
    }

    /** setLastDay: the picked day is the phase's last day, so the length counts it. With no start, nothing changes. */
    private static function lastDay(Draft $draft, DateTimeImmutable $lastDay): Draft
    {
        if ($draft->startDate === null) {
            return $draft;
        }
        $days = OnboardingDates::daysBetween($draft->startDate, $lastDay) + 1;

        return $draft->with(weeks: self::clampedWeeks($draft, $days / 7));
    }

    /**
     * setTargetWeightValue: the length that reaches the target at the current rate, within the limits. A target in the
     * wrong direction takes the shortest length. With a rate of 0 there is no target to move to, and nothing changes.
     */
    private static function targetWeight(Draft $draft, OnboardingWeightAnswer $target, DateTimeImmutable $today): Draft
    {
        $weight = $draft->weightKg;
        $rate = $draft->ratePercentPerWeek;
        if ($target->value === null || ! (new OnboardingRateLength($draft, $today))->canEditTarget() || $weight === null || $rate === null || $weight <= 0) {
            return $draft;
        }
        $exact = (OnboardingText::kgFrom($target->value, WeightUnit::from($target->unit)) / $weight - 1) / ($rate / 100);

        return $draft->with(weeks: self::clampedWeeks($draft, is_finite($exact) ? max($exact, 0.0) : 0.0));
    }

    /** clampedWeeks: rounds to whole weeks inside the goal's TBL-010 length limits (ONB-009). */
    private static function clampedWeeks(Draft $draft, float|int $exact): int
    {
        $range = OnboardingRateLength::weeksRangeFor($draft->goal ?? PhaseType::Maintenance);

        return (int) min(max(round($exact), $range->lower), $range->upper);
    }

    /**
     * toggleTrainingDay, setTrainingStart, setTrainingLength: the list replaces the saved one. A time or length left
     * out keeps the saved one, or a new day's default of 18:00 for 60 minutes (ONB-011).
     *
     * @param  list<OnboardingTrainingDayAnswer>  $answers
     */
    private static function trainingDays(Draft $draft, array $answers): Draft
    {
        $saved = [];
        foreach ($draft->trainingDays as $day) {
            $saved[$day->weekday] = $day;
        }
        $days = [];
        foreach ($answers as $answer) {
            $old = $saved[$answer->weekday] ?? null;
            $days[$answer->weekday] = new TrainingDayChoice(
                $answer->weekday,
                self::minuteOfDay($answer->start_minutes ?? $old->startMinutes ?? OnboardingTraining::DEFAULT_START_MINUTES),
                self::clamp(
                    $answer->length_minutes ?? $old->lengthMinutes ?? OnboardingTraining::DEFAULT_LENGTH_MINUTES,
                    OnboardingTraining::MINIMUM_LENGTH_MINUTES, OnboardingTraining::MAXIMUM_LENGTH_MINUTES,
                ),
            );
        }
        ksort($days);

        return $draft->with(trainingDays: array_values($days));
    }

    /**
     * selectTemplate, chooseMesoBuilder, buildLater (ONB-012): a template must be one step 9 offers for these answers.
     *
     * @param  list<OnboardingTemplate>  $templates
     */
    private static function trainingPlan(Draft $draft, string $value, array $templates): Draft
    {
        if (str_starts_with($value, self::TEMPLATE_PREFIX)) {
            $id = substr($value, strlen(self::TEMPLATE_PREFIX));
            $offered = array_map(fn (TemplateOption $option) => (string) $option->template->id, (new OnboardingTraining($draft, $templates))->templateOptions());
            if (! in_array($id, $offered, true)) {
                throw new FieldRefused('answers.training_plan', self::PLAN_NOT_OFFERED);
            }

            return $draft->with(trainingPlan: TrainingPlanKind::Template, templateId: (int) $id);
        }
        $kind = TrainingPlanKind::tryFrom($value);
        if ($kind === null || $kind === TrainingPlanKind::Template) {
            throw new FieldRefused('answers.training_plan', self::PLAN_NOT_OFFERED);
        }

        return $draft->with(trainingPlan: $kind, templateId: null);
    }

    /**
     * setStepRange: the list replaces the saved one, and a minimum above the maximum raises the maximum (ONB-013).
     *
     * @param  list<OnboardingStepRangeAnswer>  $answers
     */
    private static function stepRanges(array $answers, Draft $draft): Draft
    {
        $ranges = [];
        foreach ($answers as $answer) {
            $low = self::clamp($answer->min, 0, OnboardingLifestyle::MAXIMUM_DAILY_STEPS);
            $ranges[$answer->weekday] = new StepRangeChoice($answer->weekday, $low, self::clamp($answer->max, $low, OnboardingLifestyle::MAXIMUM_DAILY_STEPS));
        }
        ksort($ranges);

        return $draft->with(stepRanges: array_values($ranges));
    }

    /**
     * addBusyPeriod, updateBusyPeriod, removeBusyPeriod (ONB-014): the list replaces the saved one. A period the draft
     * knows by id keeps its id, and its label or weekdays when the answer's are empty; any other period is new.
     *
     * @param  list<OnboardingBusyPeriodAnswer>  $answers
     * @param  Closure(): string  $newId
     */
    private static function busyPeriods(Draft $draft, array $answers, Closure $newId): Draft
    {
        $saved = [];
        foreach ($draft->busyPeriods as $period) {
            $saved[$period->id] = $period;
        }
        $periods = [];
        foreach ($answers as $answer) {
            $old = $answer->id === null ? null : $saved[$answer->id] ?? null;
            unset($saved[$answer->id ?? '']);
            $label = trim($answer->label);
            $weekdays = array_values(array_unique(array_filter($answer->weekdays, fn (mixed $day) => in_array($day, range(1, 7), true))));
            sort($weekdays);
            $weekdays = $weekdays === [] ? $old?->weekdays : $weekdays;
            if ($weekdays === null) {
                throw new FieldRefused('answers.busy_periods', self::BUSY_PERIOD_NEEDS_A_DAY);
            }
            $periods[] = new BusyPeriodChoice(
                $old->id ?? $newId(),
                $label === '' ? $old->label ?? self::BUSY_LABEL : $label,
                self::minuteOfDay($answer->start_minutes),
                self::minuteOfDay($answer->end_minutes),
                $weekdays,
            );
        }

        return $draft->with(busyPeriods: $periods);
    }

    /**
     * toggleWeighInDay, setWeighInTime (ONB-015): the list replaces the saved one. A time left out keeps the saved one,
     * or a new day's default of 07:00.
     *
     * @param  list<OnboardingWeighInAnswer>  $answers
     */
    private static function weighIns(Draft $draft, array $answers): Draft
    {
        $saved = [];
        foreach ($draft->weighIns as $weighIn) {
            $saved[$weighIn->weekday] = $weighIn;
        }
        $days = [];
        foreach ($answers as $answer) {
            $minutes = $answer->minutes ?? ($saved[$answer->weekday] ?? null)->minutes ?? OnboardingLifestyle::DEFAULT_WEIGH_IN_MINUTES;
            $days[$answer->weekday] = new WeighInChoice($answer->weekday, self::minuteOfDay($minutes));
        }
        ksort($days);

        return $draft->with(weighIns: array_values($days));
    }

    /**
     * chooseOwnCalories starts from the recommended average; useRecommendedCalories drops the chosen one (ONB-017).
     *
     * @param  Closure(Draft): list<OnboardingTemplate>  $templates
     */
    private static function calories(Draft $draft, bool $own, DateTimeImmutable $today, Closure $templates): Draft
    {
        if (! $own) {
            return $draft->with(choosesOwnCalories: false, ownDailyKcal: null);
        }
        $kcal = $draft->ownDailyKcal;
        if ($kcal === null) {
            $average = (new OnboardingCalories($draft, $today, $templates($draft)))->recommended()?->averageKcal;
            $kcal = $average === null ? null : (int) round($average);
        }

        return $draft->with(choosesOwnCalories: true, ownDailyKcal: $kcal);
    }

    /** A calendar day as the rules read it: midnight in UTC. */
    private static function day(string $date): DateTimeImmutable
    {
        return OnboardingDates::day(new DateTimeImmutable($date, new DateTimeZone('UTC')));
    }

    private static function minuteOfDay(int $minutes): int
    {
        return self::clamp($minutes, 0, 24 * 60 - 1);
    }

    private static function clamp(int $value, int $lower, int $upper): int
    {
        return min(max($value, $lower), $upper);
    }
}
