<?php

namespace App\Domain\Progress;

use App\Domain\Model\Phase;
use App\Domain\Weight\TrendPoint;
use App\Domain\Weight\TrendWeight;
use DateTimeImmutable;
use DateTimeZone;

/** ANA-001: the series the weight chart draws, for the current phase or the whole plan. */
final readonly class ProgressChartData
{
    /**
     * @param  list<ChartWeightPoint>  $weights  the daily weights: only days that have a weigh-in
     * @param  list<ChartWeightPoint>  $trend  the trend weight, every day
     * @param  list<ChartGoalPoint>  $goal  each phase's goal line on its own days
     */
    public function __construct(
        public array $weights,
        public array $trend,
        public array $goal,
    ) {}

    /**
     * Points run from the scope's start through today: the current phase's start, or the plan's.
     * With no phase running today, "current phase" falls back to the whole plan. Each day's range
     * is that day's own phase's (GoalRange).
     *
     * @param  list<Phase>  $phases
     */
    public static function make(
        ProgressScope $scope, TrendWeight $trend, array $phases, DateTimeImmutable $planStart,
        DateTimeImmutable $today, DateTimeZone $zone,
    ): self {
        $todayStart = Days::startOf($today, $zone);
        $scopeStart = $scope->start($phases, $planStart, $today, $zone);
        $days = array_values(array_filter(
            $trend->points, fn (TrendPoint $point) => $point->day >= $scopeStart && $point->day <= $todayStart,
        ));

        $ranges = [];
        foreach ($phases as $phase) {
            $range = GoalRange::make($phase, $trend, $zone);
            if ($range !== null) {
                $ranges[$phase->id] = $range;
            }
        }
        $goal = [];
        foreach ($days as $point) {
            $phase = PhaseDays::current($phases, $point->day, $zone);
            $range = $phase === null ? null : ($ranges[$phase->id] ?? null);
            if ($phase === null || $range === null) {
                continue;
            }
            $value = $range->point($point->day);
            $goal[] = new ChartGoalPoint($phase->id, $point->day, $value->goalKg, $value->lowerKg, $value->upperKg);
        }

        return new self(
            weights: array_values(array_map(
                fn (TrendPoint $point) => new ChartWeightPoint($point->day, $point->weightKg),
                array_filter($days, fn (TrendPoint $point) => $point->isWeighIn),
            )),
            trend: array_map(fn (TrendPoint $point) => new ChartWeightPoint($point->day, $point->trendKg), $days),
            goal: $goal,
        );
    }
}
