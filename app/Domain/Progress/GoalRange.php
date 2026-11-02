<?php

namespace App\Domain\Progress;

use App\Domain\Defaults\TBL050OnTargetBand;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Phases\MaintenanceHold;
use App\Domain\Weight\TrendPoint;
use App\Domain\Weight\TrendWeight;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ANA-001: the goal line and range a phase draws on the weight chart.
 *
 * Settled rules (the spec leaves them open):
 * - S, the start trend weight, is the trend on the phase's start day, or the first trend point after it
 *   when there was no weigh-in yet (the "anchor day"). No trend point inside the phase means no range.
 * - The goal line on day d is `S x (1 + rate / 100 x w)`, with `rate` the phase's signed
 *   percent per week and `w` the weeks since the anchor day (fractional, never negative).
 * - Maintenance uses the REV-029 corridor: plus or minus 1% of S (`MaintenanceHold::corridor`),
 *   the same width all phase long, centred on the goal line.
 * - Every other phase uses the TBL-050 band, `max(0.15, 0.25 x |rate|)` percent of S per week,
 *   times `w`: a cone that starts at the line and widens.
 */
final readonly class GoalRange
{
    private function __construct(
        public Phase $phase,
        public DateTimeImmutable $anchorDay,
        /** S, the trend weight on the anchor day. */
        public float $startTrendKg,
        private DateTimeZone $zone,
    ) {}

    /** null when the trend has no point inside the phase. */
    public static function make(Phase $phase, TrendWeight $trend, DateTimeZone $zone): ?self
    {
        $anchor = self::firstPointIn($phase, $trend, $zone);

        return $anchor === null ? null : new self($phase, $anchor->day, $anchor->trendKg, $zone);
    }

    public function point(DateTimeImmutable $day): GoalRangePoint
    {
        $weeks = max(0, Days::whole($this->anchorDay, $day, $this->zone) / 7);
        $rate = $this->phase->targetRatePercentPerWeek;
        $goal = $this->startTrendKg * (1 + $rate / 100 * $weeks);
        if ($this->phase->type === PhaseType::Maintenance) {
            $halfWidth = MaintenanceHold::corridor($this->startTrendKg)->upper - $this->startTrendKg;
        } else {
            $percentPerWeek = max(
                TBL050OnTargetBand::ABSOLUTE_PERCENT_BODYWEIGHT_PER_WEEK,
                TBL050OnTargetBand::RELATIVE_PERCENT_OF_TARGET_RATE / 100 * abs($rate),
            );
            $halfWidth = $this->startTrendKg * $percentPerWeek / 100 * $weeks;
        }

        return new GoalRangePoint(Days::startOf($day, $this->zone), $goal, $goal - $halfWidth, $goal + $halfWidth);
    }

    /** The first trend point from the phase's start day up to, not including, its end. */
    public static function firstPointIn(Phase $phase, TrendWeight $trend, DateTimeZone $zone): ?TrendPoint
    {
        $start = Days::startOf($phase->start, $zone);
        $end = $phase->end === null ? null : Days::startOf($phase->end, $zone);
        foreach ($trend->points as $point) {
            if ($point->day >= $start && ($end === null || $point->day < $end)) {
                return $point;
            }
        }

        return null;
    }
}
