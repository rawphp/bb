<?php

namespace App\Domain\Review;

use App\Domain\Energy\EnergyRules;
use App\Domain\Model\StepPlanDay;
use App\Domain\Progress\PlanWeekDays;
use DateTimeImmutable;

/** REV-050: compare actual steps with the step plan. */
final class StepPlanCheck
{
    /** REV-050: offer when the daily average differs by more than this. */
    public const DIFFERENCE_ABOVE = 2_000;

    /**
     * Null without step data, or when the averages are within the difference.
     *
     * @param  list<int>  $actualDailySteps
     * @param  list<int>  $plannedDailySteps
     */
    public static function offer(array $actualDailySteps, array $plannedDailySteps): ?StepPlanOffer
    {
        if ($actualDailySteps === [] || $plannedDailySteps === []) {
            return null;
        }
        $actual = array_sum($actualDailySteps) / count($actualDailySteps);
        $planned = array_sum($plannedDailySteps) / count($plannedDailySteps);
        if (abs($actual - $planned) <= self::DIFFERENCE_ABOVE) {
            return null;
        }

        return new StepPlanOffer(actualAverage: (float) $actual, plannedAverage: (float) $planned);
    }

    /**
     * REV-050: the offer to update the step plan, from the actual steps on the review week's days (keyed by the Unix
     * time of the start of the day). A day without data counts for neither side. A planned day is the middle of its
     * range; a day with no range is left out. Days are read in the time zone of the dates handed in.
     *
     * @param  array<int, int>  $actualSteps
     * @param  list<DateTimeImmutable>  $days
     * @param  list<StepPlanDay>  $plan
     */
    public static function offerForWeek(array $actualSteps, array $days, array $plan): ?StepPlanOffer
    {
        $actual = [];
        $planned = [];
        foreach ($days as $day) {
            $weekday = PlanWeekDays::isoWeekday($day, $day->getTimezone());
            $count = $actualSteps[$day->setTime(0, 0)->getTimestamp()] ?? null;
            $range = null;
            foreach ($plan as $candidate) {
                if ($candidate->weekday === $weekday) {
                    $range = $candidate;
                    break;
                }
            }
            if ($count === null || $range === null) {
                continue;
            }
            $actual[] = $count;
            $planned[] = EnergyRules::stepMidpoint($range);
        }

        return self::offer($actual, $planned);
    }
}
