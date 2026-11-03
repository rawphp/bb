<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\ClosedRange;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\MealPlacement;
use App\Domain\Placement\MealSlot;
use App\Domain\Safety\SafetyWarnings;

/**
 * Steps 10 to 12 (ONB-013 to ONB-016, SAFE-015), the read members of rp-clone's OnboardingModel+Lifestyle: daily
 * steps, meals, weigh-ins and the review day. Weekdays are ISO, 1 = Monday.
 */
final readonly class OnboardingLifestyle
{
    public const MAXIMUM_DAILY_STEPS = 40_000;

    public const MINIMUM_MEALS = 2;

    public const MAXIMUM_MEALS = 6;

    /** The profile and the week schedule hold one eating window, so there is no per-day window. */
    public const EATING_WINDOW_NOTE = 'This window applies to every day.';

    public const MEALS_DO_NOT_FIT_HINT = "The eating window can't hold that many meals. Widen it, remove a busy period or have fewer meals.";

    public const TOO_FEW_WEIGH_INS = 'Too few weigh-ins to continue';

    public const MINIMUM_WEIGH_IN_DAYS = 2;

    public const RECOMMENDED_WEIGH_IN_DAYS = 4;

    public const WEIGH_IN_HINT = 'We recommend weighing in on 4 or more days a week for a clearer trend.';

    public const WEIGH_IN_TIME_NOTE = 'The time only sets your reminder. Weigh in first thing in the morning.';

    public const DEFAULT_WEIGH_IN_MINUTES = 7 * 60;

    public function __construct(private Draft $draft) {}

    // Step 10 (ONB-013, SAFE-015)

    /** @return list<StepPreset> */
    public static function stepPresets(): array
    {
        return [
            new StepPreset(0, 5_000), new StepPreset(0, 8_000), new StepPreset(5_000, 8_000),
            new StepPreset(8_000, 10_000), new StepPreset(10_000, 12_000), new StepPreset(8_000, 13_000),
        ];
    }

    /** Monday to Friday, then the weekend. */
    public static function defaultStepRange(int $weekday): StepRangeChoice
    {
        return $weekday <= 5 ? new StepRangeChoice($weekday, 5_000, 8_000) : new StepRangeChoice($weekday, 8_000, 10_000);
    }

    /**
     * All seven weekdays, Monday first: the user's range, or the default until they change one.
     *
     * @return list<StepRangeChoice>
     */
    public function stepRanges(): array
    {
        return array_map(function (int $weekday) {
            foreach ($this->draft->stepRanges as $range) {
                if ($range->weekday === $weekday) {
                    return $range;
                }
            }

            return self::defaultStepRange($weekday);
        }, range(1, 7));
    }

    public function stepRange(int $weekday): StepRangeChoice
    {
        return $this->stepRanges()[min(max($weekday, 1), 7) - 1];
    }

    /** The range every day of the group shares; null when they differ. */
    public function groupRange(StepDayGroup $group): ?ClosedRange
    {
        $ranges = array_map(fn (int $weekday) => $this->stepRange($weekday), $group->weekdays());
        foreach ($ranges as $range) {
            if ($range->min !== $ranges[0]->min || $range->max !== $ranges[0]->max) {
                return null;
            }
        }

        return new ClosedRange($ranges[0]->min, $ranges[0]->max);
    }

    /** SAFE-015: a step target above 12,000 in a fat-loss phase. It warns and never blocks. */
    public function stepWarning(): ?string
    {
        $goal = $this->draft->goal;
        if ($goal === null) {
            return null;
        }
        $highest = max(array_map(fn (StepRangeChoice $range) => $range->max, $this->stepRanges()));

        return SafetyWarnings::stepTarget($highest, $goal)?->message();
    }

    // Step 11 (ONB-014)

    /** An end earlier than the start crosses midnight (SCH-045). */
    public function eatingWindow(): ClockRange
    {
        return new ClockRange(new ClockTime($this->draft->eatingStartMinutes), new ClockTime($this->draft->eatingEndMinutes));
    }

    /** @return list<ClockRange> */
    public function busyRanges(): array
    {
        return array_map(
            fn (BusyPeriodChoice $period) => new ClockRange(new ClockTime($period->startMinutes), new ClockTime($period->endMinutes)),
            $this->draft->busyPeriods,
        );
    }

    /**
     * Whether the window holds the meals around the busy periods (SCH-041). A busy period counts on every day, as the
     * week schedule takes one list for the week.
     */
    public function mealsFit(): bool
    {
        $window = $this->eatingWindow();
        if ($window->lengthInMinutes() <= 0) {
            return false;
        }
        $meals = array_map(fn (int $index) => new MealSlot("meal-{$index}"), range(1, max(1, $this->draft->mealCount)));

        return MealPlacement::place($meals, $window, $this->busyRanges()) !== null;
    }

    // Step 12 (ONB-015, ONB-016)

    public function hasEnoughWeighIns(): bool
    {
        return count($this->draft->weighIns) >= self::MINIMUM_WEIGH_IN_DAYS;
    }

    /** The day after the last weigh-in day of the week (ONB-016), Monday if there are none. */
    public function defaultReviewWeekday(): int
    {
        $weekdays = array_map(fn (WeighInChoice $weighIn) => $weighIn->weekday, $this->draft->weighIns);

        return $weekdays === [] ? 1 : max($weekdays) % 7 + 1;
    }

    /** The day of the weekly review, which is also the first day of every plan week (PLAN-008). */
    public function reviewWeekday(): int
    {
        return $this->draft->reviewWeekday ?? $this->defaultReviewWeekday();
    }
}
