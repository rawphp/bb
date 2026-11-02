<?php

namespace App\Domain\Plan;

use App\Domain\Plan\MissedWeeks\Week;
use DateTimeImmutable;

/**
 * SCH-058: missing a week or more of training. The user is asked to restart the week they stopped in, or to end
 * the mesocycle and build a new one.
 */
final class MissedWeeks
{
    /**
     * Due when a whole plan week has ended after the week of the last started session (`$lastStartedWeek`, null
     * when none ever was) in which the mesocycle placed sessions and started none; a skipped session is not
     * started. The week in progress never counts. A paused away week places no sessions, so it never makes the
     * prompt due.
     *
     * @param  list<Week>  $weeks
     */
    public static function isDue(array $weeks, ?int $lastStartedWeek, int $currentWeek): bool
    {
        foreach ($weeks as $week) {
            if ($week->index < $currentWeek && $week->index > ($lastStartedWeek ?? PHP_INT_MIN)
                && $week->sessions > 0 && $week->started === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restart: the whole plan weeks the start of `$mesocycles[$index]` moves forward so that its week
     * `$mesocycleWeek` (from 0) is `$currentWeek`, laid out with the others as PlanRules::placements() does. When
     * `$currentWeek` is a paused week the stopped week lands on the first one after it. 0 when it is already there
     * or later.
     *
     * @param  list<?DatedMesocycle>  $mesocycles
     * @param  list<int>  $pausedWeeks
     */
    public static function restartShift(
        int $mesocycleWeek, int $index, array $mesocycles, int $currentWeek, PlanWeekGrid $grid, array $pausedWeeks = [],
    ): int {
        $stored = $mesocycles[$index] ?? null;
        if ($stored === null) {
            return 0;
        }
        // Each extra week moves the stopped week at least one plan week, so the search ends within this bound.
        $limit = max(0, $currentWeek - $grid->weekIndexCountingBack($stored->start)) + count(array_unique($pausedWeeks)) + 1;
        for ($shift = 0; $shift <= $limit; $shift++) {
            $moved = $mesocycles;
            $moved[$index] = new DatedMesocycle($stored->start->modify(sprintf('+%d days', 7 * $shift)), $stored->weeks);
            $placed = PlanRules::placements($moved, $grid, $pausedWeeks)[$index];
            if ($placed === null) {
                return 0;
            }
            if (PlanRules::planWeek($mesocycleWeek, $placed->startWeek, $pausedWeeks) >= $currentWeek) {
                return $shift;
            }
        }

        return $limit;
    }

    /**
     * Restart: where each deload flagged on a plan week inside `$mesocycles[$index]` goes (PLAN-022) once its start
     * moves to `$restartedOn`: the week its mesocycle week then falls on. A flag outside the mesocycle, or one that
     * stays, is left out.
     *
     * @param  list<int>  $flagged
     * @param  list<?DatedMesocycle>  $mesocycles
     * @param  list<int>  $pausedWeeks
     * @return list<MovedDeload>
     */
    public static function movedDeloads(
        array $flagged, int $index, array $mesocycles, DateTimeImmutable $restartedOn, PlanWeekGrid $grid,
        array $pausedWeeks = [],
    ): array {
        $stored = $mesocycles[$index] ?? null;
        $old = PlanRules::placements($mesocycles, $grid, $pausedWeeks)[$index] ?? null;
        if ($stored === null || $old === null) {
            return [];
        }
        $moved = $mesocycles;
        $moved[$index] = new DatedMesocycle($restartedOn, $stored->weeks);
        $new = PlanRules::placements($moved, $grid, $pausedWeeks)[$index];
        if ($new === null) {
            return [];
        }

        $moves = [];
        foreach ($flagged as $week) {
            if (! $old->contains($week)) {
                continue;
            }
            $mesocycleWeek = PlanRules::mesocycleWeek($week, $old->startWeek, $pausedWeeks);
            $target = PlanRules::planWeek($mesocycleWeek, $new->startWeek, $pausedWeeks);
            if ($target !== $week) {
                $moves[] = new MovedDeload($week, $target);
            }
        }

        return $moves;
    }
}
