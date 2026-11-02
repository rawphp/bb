<?php

namespace App\Domain\Progress;

use App\Domain\Model\Phase;
use App\Domain\Weight\TrendWeight;
use DateTimeImmutable;
use DateTimeZone;

final class PhaseHistory
{
    /**
     * Past phases are those with an end on or before today, newest first.
     *
     * The start trend weight is the first trend point inside the phase (as in GoalRange); the end one is the
     * trend on the last day before the phase end, or the latest earlier trend point when weigh-ins stopped
     * sooner (WT-010 holds the last value). The rate is the change over the phase's whole weeks.
     *
     * @param  list<Phase>  $phases
     * @param  list<ProgressMesocycle>  $mesocycles
     * @return list<PhaseHistoryEntry>
     */
    public static function entries(
        array $phases, array $mesocycles, TrendWeight $trend, DateTimeImmutable $today, DateTimeZone $zone,
    ): array {
        $todayStart = Days::startOf($today, $zone);
        $past = [];
        foreach ($phases as $phase) {
            $end = $phase->end === null ? null : Days::startOf($phase->end, $zone);
            if ($end !== null && $end <= $todayStart) {
                $past[] = [$phase, $end];
            }
        }
        usort($past, fn (array $a, array $b) => $b[0]->start <=> $a[0]->start);

        return array_map(function (array $entry) use ($mesocycles, $trend, $zone) {
            [$phase, $end] = $entry;
            $start = Days::startOf($phase->start, $zone);
            $first = GoalRange::firstPointIn($phase, $trend, $zone);
            $last = null;
            foreach ($trend->points as $point) {
                if ($point->day < $end) {
                    $last = $point;
                }
            }
            $change = null;
            $rate = null;
            if ($first !== null && $last !== null && $last->day >= $first->day) {
                $change = $last->trendKg - $first->trendKg;
                // Over the phase's whole weeks, as rp-clone does and its ANA-006 test pins: a trend that starts late or
                // stops early (WT-010 holds it) counts as no change on those days, not as a shorter phase.
                $weeks = Days::whole($start, $end, $zone) / 7;
                if ($weeks > 0) {
                    $rate = $change / $first->trendKg * 100 / $weeks;
                }
            }
            $own = array_values(array_filter($mesocycles, fn (ProgressMesocycle $meso) => $meso->phaseId === $phase->id));
            usort($own, fn (ProgressMesocycle $a, ProgressMesocycle $b) => $a->start <=> $b->start);

            return new PhaseHistoryEntry(
                id: $phase->id, type: $phase->type, start: $start, end: $end,
                weightChangeKg: $change, averageRatePercentPerWeek: $rate, mesocycles: $own,
            );
        }, $past);
    }
}
