<?php

namespace App\Domain\Progress;

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Weight\TrendWeight;
use DateTimeImmutable;
use DateTimeZone;

/** ANA-002: start weight, change so far and goal weight, from the trend weight (WT-011). */
final readonly class ProgressSummary
{
    public function __construct(
        /** The trend weight at the start of the scope. */
        public float $startKg,
        /** The latest trend weight. */
        public float $currentKg,
        /** null when there is no goal to name (see make()). */
        public ?float $goalKg,
        /** The phase running today, null if none. */
        public ?PhaseType $phaseType,
        public DateTimeImmutable $startDay,
        public DateTimeImmutable $currentDay,
    ) {}

    public function changeKg(): float
    {
        return $this->currentKg - $this->startKg;
    }

    /**
     * Start is the first trend point of the scope, current the latest one up to today. The goal weight is
     * the current phase's goal line on its end date; a phase with no end has none unless its rate is 0,
     * when it is the start weight. null when there is no trend data in the scope.
     *
     * @param  list<Phase>  $phases
     */
    public static function make(
        ProgressScope $scope, TrendWeight $trend, array $phases, DateTimeImmutable $planStart,
        DateTimeImmutable $today, DateTimeZone $zone,
    ): ?self {
        $todayStart = Days::startOf($today, $zone);
        $scopeStart = $scope->start($phases, $planStart, $today, $zone);
        $first = null;
        $last = null;
        foreach ($trend->points as $point) {
            if ($point->day > $todayStart) {
                break;
            }
            if ($first === null && $point->day >= $scopeStart) {
                $first = $point;
            }
            $last = $point;
        }
        if ($first === null || $last === null) {
            return null;
        }

        $phase = PhaseDays::current($phases, $today, $zone);
        $range = $phase === null ? null : GoalRange::make($phase, $trend, $zone);
        $goal = null;
        if ($phase !== null && $range !== null) {
            if ($phase->end !== null) {
                $goal = $range->point($phase->end)->goalKg;
            } elseif ($phase->targetRatePercentPerWeek == 0) {
                $goal = $range->startTrendKg;
            }
        }

        return new self(
            startKg: $first->trendKg, currentKg: $last->trendKg, goalKg: $goal, phaseType: $phase?->type,
            startDay: $first->day, currentDay: $last->day,
        );
    }
}
