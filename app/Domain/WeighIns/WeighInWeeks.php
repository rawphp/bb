<?php

namespace App\Domain\WeighIns;

use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Weight\TrendWeight;
use DateTimeImmutable;

/**
 * WT-008: the weeks the Weigh-ins list falls into. In a plan a week is the plan week (PLAN-008), so it runs from the
 * review day as Today's week strip does; before the plan it runs from the plan's first review day and ends before
 * the plan starts; with no plan it runs Monday to Sunday. Every day is a day at midnight, as the grid reads it.
 */
final class WeighInWeeks
{
    /**
     * The first and last day of the week `$day` falls in.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function of(DateTimeImmutable $day, ?PlanWeekGrid $grid): array
    {
        $day = $day->setTime(0, 0);
        $index = $grid?->weekIndexContaining($day);
        if ($grid !== null && $index !== null) {
            return [$grid->start($index), $grid->start($index + 1)->modify('-1 day')];
        }

        // PlanWeekGrid counts weekdays from 1 = Sunday; ISO from 1 = Monday.
        $isoWeekday = $grid === null ? 1 : ($grid->startReviewWeekday() + 5) % 7 + 1;
        $back = ((int) $day->format('N') - $isoWeekday + 7) % 7;
        $start = $day->modify("-{$back} days");
        $end = $start->modify('+6 days');
        if ($grid !== null && $end >= $grid->start(0)) {
            $end = $grid->start(0)->modify('-1 day');
        }

        return [$start, $end];
    }

    /**
     * The trend weight at the week's end (today, for the week under way), and its change from the day before the
     * week starts. The change is null before the first weigh-in; the trend too, when the week ends before it.
     *
     * @return array{0: ?float, 1: ?float} [trend kg, change kg]
     */
    public static function trend(TrendWeight $trend, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $today): array
    {
        $kg = $trend->kgOnOrBefore(min($end, $today));
        $before = $trend->kgOnOrBefore($start->modify('-1 day'));

        return [$kg, $kg === null || $before === null ? null : $kg - $before];
    }
}
