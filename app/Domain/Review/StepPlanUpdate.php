<?php

namespace App\Domain\Review;

use App\Domain\Energy\EnergyRules;
use App\Domain\Model\StepPlanDay;

/** Changes to the step plan the review can make (REV-027, REV-050). */
final class StepPlanUpdate
{
    /**
     * The step goal the calorie review reads (REV-027): the average of the days' range middles.
     *
     * @param  list<StepPlanDay>  $plan
     */
    public static function plannedDailySteps(array $plan): int
    {
        if ($plan === []) {
            return 0;
        }

        return intdiv(array_sum(array_map(fn (StepPlanDay $day) => EnergyRules::stepMidpoint($day), $plan)), count($plan));
    }

    /**
     * REV-027: more steps a day, on every day's range.
     *
     * @param  list<StepPlanDay>  $plan
     * @return list<StepPlanDay>
     */
    public static function adding(int $steps, array $plan): array
    {
        return array_map(
            fn (StepPlanDay $day) => new StepPlanDay($day->weekday, max(0, $day->min + $steps), max(0, $day->max + $steps)),
            $plan,
        );
    }

    /**
     * REV-050: every range moves by the gap between the steps walked and planned, to the nearest 500.
     *
     * @param  list<StepPlanDay>  $plan
     * @return list<StepPlanDay>
     */
    public static function matching(StepPlanOffer $offer, array $plan): array
    {
        $shift = (int) round(($offer->actualAverage - $offer->plannedAverage) / 500) * 500;

        return self::adding($shift, $plan);
    }
}
