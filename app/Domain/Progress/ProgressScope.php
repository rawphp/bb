<?php

namespace App\Domain\Progress;

use App\Domain\Model\Phase;
use DateTimeImmutable;
use DateTimeZone;

/** ANA-001: what the chart covers. */
enum ProgressScope: string
{
    case CurrentPhase = 'currentPhase';
    case WholePlan = 'wholePlan';

    public function title(): string
    {
        return match ($this) {
            self::CurrentPhase => 'Phase',
            self::WholePlan => 'Whole plan',
        };
    }

    /**
     * The first day the scope covers: the plan's start, or the current phase's (the plan's when none is running).
     *
     * @param  list<Phase>  $phases
     */
    public function start(array $phases, DateTimeImmutable $planStart, DateTimeImmutable $today, DateTimeZone $zone): DateTimeImmutable
    {
        return Days::startOf(match ($this) {
            self::WholePlan => $planStart,
            self::CurrentPhase => PhaseDays::current($phases, $today, $zone)->start ?? $planStart,
        }, $zone);
    }
}
