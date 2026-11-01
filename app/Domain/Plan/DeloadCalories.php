<?php

namespace App\Domain\Plan;

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;

/** Deload calories and diet breaks (PLAN-009, PLAN-022 to PLAN-025). */
final class DeloadCalories
{
    public const MAINTENANCE_LABEL = 'Deload · maintenance calories';

    public const DEFICIT_DELOAD_WARNING = 'Deloading in a deficit is one of the biggest mistakes RP warns about.';

    /**
     * PLAN-022, PLAN-024: every deload runs at maintenance. Only a Fat loss or Mini-cut deload can be turned off
     * (PLAN-023); the flag is ignored in other phases.
     */
    public static function runsAtMaintenance(PhaseType $type, bool $dietBreakTurnedOff): bool
    {
        return $type->isFatLoss() ? ! $dietBreakTurnedOff : true;
    }

    /** PLAN-023. */
    public static function canTurnOffDietBreak(PhaseType $type): bool
    {
        return $type->isFatLoss();
    }

    /** The deload week's label, or null when it runs in a deficit. */
    public static function label(PhaseType $type, bool $dietBreakTurnedOff): ?string
    {
        return self::runsAtMaintenance($type, $dietBreakTurnedOff) ? self::MAINTENANCE_LABEL : null;
    }

    /**
     * PLAN-022, PLAN-009: a Fat loss or Mini-cut deload at maintenance is a diet break. A Muscle gain or
     * Maintenance deload is a planned week, not a diet break (PLAN-024).
     */
    public static function isDietBreak(PhaseType $type, bool $isDeload, bool $dietBreakTurnedOff): bool
    {
        return $isDeload && $type->isFatLoss() && ! $dietBreakTurnedOff;
    }

    /**
     * PLAN-025: the weeks that count towards the weekly rate and the 16-week cap (deficit weeks only).
     *
     * @param  list<PlanWeek>  $weeks
     */
    public static function countedWeeks(array $weeks): int
    {
        return count(array_filter($weeks, fn (PlanWeek $week) => ! $week->isDietBreak));
    }

    /**
     * PLAN-025: each unplanned break moves the end out one week unless the user keeps the end date. Planned
     * deloads never move it. A phase with no end is unchanged.
     *
     * @param  list<UnplannedBreak>  $breaks
     */
    public static function extendingEnd(Phase $phase, array $breaks, bool $keepingEndDate, PlanWeekGrid $grid): Phase
    {
        $weeks = PlanRules::weeks($phase, $grid);
        if ($keepingEndDate || $breaks === [] || $weeks === null || $phase->end === null) {
            return $phase;
        }

        return new Phase(
            id: $phase->id,
            type: $phase->type,
            start: $phase->start,
            end: $grid->start($weeks->upper + count($breaks)),
            targetRatePercentPerWeek: $phase->targetRatePercentPerWeek,
            eventDate: $phase->eventDate,
        );
    }
}
