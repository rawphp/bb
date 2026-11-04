<?php

namespace App\Capabilities\Reads\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingBusyPeriod;
use App\Capabilities\Onboarding\Data\OnboardingCalorieDay;
use App\Capabilities\Onboarding\Data\OnboardingCaloriesSection;
use App\Capabilities\Onboarding\Data\OnboardingMealsSection;
use App\Capabilities\Onboarding\Data\OnboardingStepDay;
use App\Capabilities\Onboarding\Data\OnboardingStepGroup;
use App\Capabilities\Onboarding\Data\OnboardingStepPreset;
use App\Capabilities\Onboarding\Data\OnboardingStepsSection;
use App\Capabilities\Onboarding\Data\OnboardingWeighInDay;
use App\Capabilities\Onboarding\Data\OnboardingWeighInsSection;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\CalorieDay;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingCalories;
use App\Domain\Onboarding\OnboardingLifestyle;
use App\Domain\Onboarding\OnboardingText;
use App\Domain\Onboarding\StepDayGroup;
use App\Domain\Onboarding\StepPreset;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\WeighInChoice;

/** Steps 10 to 13 of onboarding_show: daily steps, meals, weigh-ins and calories. */
final class OnboardingLifestylePayload
{
    private const STEP_INCREMENT = 500;

    public const STEPS_NOTE = 'Your typical daily step count. A range for each day is fine.';

    public const MIXED_STEPS_TEXT = 'The days differ';

    public const BUSY_NOTE = "Times you can't eat, such as a commute or a meeting. Meals are placed around them.";

    public const REVIEW_DAY_NOTE = 'The day you look back on the week and set the next one. It is also the first day of every plan week. It starts as the day after your last weigh-in day.';

    public const RECOMMENDATION = 'Our recommendation';

    public const OWN_CALORIES = 'Choose my own average daily calories';

    public const WEEK_NOTE = 'A typical plan week. Training days get more than rest days.';

    public const OWN_UNIT = 'kcal a day';

    public static function steps(OnboardingLifestyle $lifestyle): OnboardingStepsSection
    {
        return new OnboardingStepsSection(
            note: self::STEPS_NOTE,
            presets: array_map(fn (StepPreset $preset) => new OnboardingStepPreset(min: $preset->min, max: $preset->max, title: $preset->title()), OnboardingLifestyle::stepPresets()),
            groups: array_map(function (StepDayGroup $group) use ($lifestyle) {
                $range = $lifestyle->groupRange($group);

                return new OnboardingStepGroup(
                    key: $group->value, title: $group->title(), weekdays: $group->weekdays(), is_mixed: $range === null,
                    min: (int) ($range->lower ?? 0), max: (int) ($range->upper ?? 0),
                    text: $range === null ? self::MIXED_STEPS_TEXT : self::stepsText((int) $range->lower, (int) $range->upper),
                );
            }, StepDayGroup::cases()),
            days: array_map(fn (StepRangeChoice $range) => new OnboardingStepDay(
                weekday: $range->weekday, full_name: OnboardingText::weekdayFullName($range->weekday), min: $range->min, max: $range->max,
                text: self::stepsText($range->min, $range->max),
            ), $lifestyle->stepRanges()),
            increment: self::STEP_INCREMENT,
            max_steps: OnboardingLifestyle::MAXIMUM_DAILY_STEPS,
        );
    }

    public static function meals(Draft $draft): OnboardingMealsSection
    {
        return new OnboardingMealsSection(
            first_meal: OnboardingParts::time($draft->eatingStartMinutes),
            last_meal: OnboardingParts::time($draft->eatingEndMinutes),
            window_note: OnboardingLifestyle::EATING_WINDOW_NOTE,
            meal_count: OnboardingParts::slider(
                $draft->mealCount, OnboardingLifestyle::MINIMUM_MEALS, OnboardingLifestyle::MAXIMUM_MEALS, 1, "Meals a day: {$draft->mealCount}",
            ),
            busy_note: self::BUSY_NOTE,
            busy_periods: array_map(fn (BusyPeriodChoice $period) => new OnboardingBusyPeriod(
                id: $period->id, label: $period->label, start: OnboardingParts::time($period->startMinutes),
                end: OnboardingParts::time($period->endMinutes), weekdays: $period->weekdays,
                weekdays_text: OnboardingText::weekdaysText($period->weekdays),
            ), $draft->busyPeriods),
        );
    }

    public static function weighIns(Draft $draft, OnboardingLifestyle $lifestyle): OnboardingWeighInsSection
    {
        $review = $lifestyle->reviewWeekday();

        return new OnboardingWeighInsSection(
            days: array_map(function (int $weekday) use ($draft) {
                $chosen = array_find($draft->weighIns, fn (WeighInChoice $weighIn) => $weighIn->weekday === $weekday);

                return new OnboardingWeighInDay(
                    weekday: $weekday, name: OnboardingText::weekdayName($weekday), full_name: OnboardingText::weekdayFullName($weekday),
                    is_on: $chosen !== null, time: OnboardingParts::time($chosen->minutes ?? OnboardingLifestyle::DEFAULT_WEIGH_IN_MINUTES),
                );
            }, range(1, 7)),
            time_note: OnboardingLifestyle::WEIGH_IN_TIME_NOTE,
            hint: count($draft->weighIns) >= OnboardingLifestyle::RECOMMENDED_WEIGH_IN_DAYS ? '' : OnboardingLifestyle::WEIGH_IN_HINT,
            review_note: self::REVIEW_DAY_NOTE,
            review_days: array_map(fn (int $weekday) => OnboardingParts::choice((string) $weekday, OnboardingText::weekdayName($weekday), $weekday === $review), range(1, 7)),
        );
    }

    public static function calories(Draft $draft, OnboardingCalories $calories): OnboardingCaloriesSection
    {
        $recommended = $calories->recommended();
        $own = $draft->choosesOwnCalories;

        return new OnboardingCaloriesSection(
            options: [
                OnboardingParts::choice(
                    'recommendation', self::RECOMMENDATION, ! $own, $recommended === null ? '' : OnboardingText::kcalText($recommended->averageKcal),
                    recommended: true,
                ),
                OnboardingParts::choice('own', self::OWN_CALORIES, $own),
            ],
            own_kcal: $draft->ownDailyKcal,
            own_kcal_max: OnboardingCalories::MAXIMUM_OWN_DAILY_KCAL,
            own_unit: self::OWN_UNIT,
            week_note: self::WEEK_NOTE,
            week: array_map(fn (CalorieDay $day) => new OnboardingCalorieDay(
                name: $day->name, kcal_text: OnboardingText::numberText((int) round($day->kcal)).' kcal',
            ), $calories->selected()->days ?? []),
        );
    }

    /** "5,000 to 8,000 steps". */
    private static function stepsText(int $min, int $max): string
    {
        return OnboardingText::numberText($min).' to '.OnboardingText::numberText($max).' steps';
    }
}
