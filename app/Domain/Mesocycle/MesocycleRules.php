<?php

namespace App\Domain\Mesocycle;

use App\Domain\Model\PhaseType;
use App\Domain\Plan\DeloadCalories;
use App\Domain\Plan\PlanWeekGrid;
use DateTimeImmutable;

/**
 * The mesocycle rules the Train reads and the mesocycle writes share, beside {@see MesocycleCalendar}: when a new
 * mesocycle starts (MESO-004, MESO-060), whose priorities a copy takes (MESO-065), and the diet breaks (MESO-041,
 * MESO-044). Without a plan, `$grid` is null.
 */
final class MesocycleRules
{
    private const WEEKDAYS = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** MESO-004: today when it begins a plan week, otherwise the next plan week's first day. */
    public static function defaultStart(?PlanWeekGrid $grid, DateTimeImmutable $today): DateTimeImmutable
    {
        if ($grid === null) {
            return $today;
        }
        $index = $grid->weekIndexContaining($today);
        if ($index === null) {
            return $grid->start(0);
        }

        return $grid->isBoundary($today) ? $today : $grid->start($index + 1);
    }

    /** MESO-004: a start moves to the first day of its plan week. */
    public static function snapped(?PlanWeekGrid $grid, DateTimeImmutable $date): DateTimeImmutable
    {
        return $grid === null ? $date : $grid->start($grid->weekIndexContaining($date) ?? 0);
    }

    /**
     * MESO-060: the next mesocycle starts the plan week after the one ending (`$planned`), but never before the default
     * start (MESO-004), so the prompt of a mesocycle finished weeks ago does not point into the past.
     */
    public static function nextMesocycleStart(DateTimeImmutable $planned, DateTimeImmutable $defaultStart): DateTimeImmutable
    {
        return max($planned, $defaultStart);
    }

    /**
     * MESO-060: a copy's start when none is given: the next mesocycle's start when it copies the one ending (`$next`),
     * else the default start.
     */
    public static function copyStart(?DateTimeImmutable $next, DateTimeImmutable $defaultStart): DateTimeImmutable
    {
        return $next ?? $defaultStart;
    }

    /**
     * MESO-065: the phase type whose priority preset a copy takes, because its phase differs in type from the source's;
     * null when the old priorities carry over.
     */
    public static function priorityReset(?PhaseType $source, ?PhaseType $new): ?PhaseType
    {
        return $source !== null && $new !== null && $source !== $new ? $new : null;
    }

    /** MESO-044: ending a fat loss mesocycle early offers a diet break next week, unless this week is one. */
    public static function endOffersDietBreak(?PhaseType $phase, bool $currentWeekIsDietBreak): bool
    {
        return ($phase?->isFatLoss() ?? false) && ! $currentWeekIsDietBreak;
    }

    /**
     * MESO-041, PLAN-022: a week is a diet break when its plan week is flagged one, or it is a fat loss deload not
     * turned off (PLAN-023). `$phase` is the phase governing the week (PLAN-002).
     */
    public static function isDietBreakWeek(bool $flagged, ?PhaseType $phase, bool $isDeload, bool $turnedOff): bool
    {
        return $flagged || ($phase !== null && DeloadCalories::isDietBreak($phase, $isDeload, $turnedOff));
    }

    /** MESO-005: an ISO weekday's name, which labels a day of a weekday split. */
    public static function weekdayName(int $weekday): string
    {
        return self::WEEKDAYS[$weekday] ?? 'Day';
    }
}
