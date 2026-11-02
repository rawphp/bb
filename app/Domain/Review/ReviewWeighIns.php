<?php

namespace App\Domain\Review;

use App\Domain\Model\PlanWeek;
use App\Domain\Model\WeighIn;

/** REV-003 step 1: the week's weigh-ins. */
final class ReviewWeighIns
{
    /**
     * The week's days that had a weigh-in scheduled or a reading, in order. Days are read in the time zone of the
     * week's start. SCH-061: in an away week weigh-ins are optional, so none is missing.
     *
     * @param  list<int>  $scheduledIsoWeekdays  the weigh-in schedule's weekdays, 1 = Monday ... 7 = Sunday
     * @param  list<WeighIn>  $weighIns
     * @return list<ReviewWeighInRow>
     */
    public static function rows(PlanWeek $week, array $scheduledIsoWeekdays, array $weighIns): array
    {
        $zone = $week->start->getTimezone();
        /** @var array<string, WeighIn> $earliest keyed by the day, "Y-m-d" */
        $earliest = [];
        foreach ($weighIns as $reading) {
            $key = $reading->date->setTimezone($zone)->format('Y-m-d');
            $kept = $earliest[$key] ?? null;
            if ($kept !== null && $kept->date <= $reading->date) {
                continue;
            }
            $earliest[$key] = $reading;
        }

        $rows = [];
        foreach ($week->days() as $day) {
            $scheduled = in_array((int) $day->format('N'), $scheduledIsoWeekdays, true);
            $reading = $earliest[$day->format('Y-m-d')] ?? null;
            if (! $scheduled && $reading === null) {
                continue;
            }
            $rows[] = new ReviewWeighInRow(
                $day, $reading?->weightKg, $scheduled, $scheduled && $reading === null && ! $week->isAway,
            );
        }

        return $rows;
    }
}
