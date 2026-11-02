<?php

namespace App\Domain\Plan;

use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use DateTimeImmutable;

/**
 * SAFE-017: a re-check that changes the phase does it from the first day of the plan week holding today. The phase
 * covering that day ends there, or is replaced in place when it starts that day; the new phase starts there. Later
 * phases and the mesocycles are not touched (PLAN-002 governs their remaining weeks). An event on or after the switch
 * day goes with the new phase, as a goal change takes it (PH-043); ChangePhaseGoal splits a phase the same way at the
 * next plan week, for a phase named by id rather than the one covering a day.
 */
final class PhaseSwitch
{
    /** The first day of the plan week holding today, or the plan's first day when it has not started. */
    public static function day(PlanWeekGrid $grid, DateTimeImmutable $today): DateTimeImmutable
    {
        return $grid->start($grid->weekIndexContaining($today) ?? 0);
    }

    /**
     * The phase that covers `$day`. A phase's end is the first day after it.
     *
     * @param  list<Phase>  $phases
     */
    public static function covering(DateTimeImmutable $day, array $phases): ?Phase
    {
        foreach ($phases as $phase) {
            if ($phase->start <= $day && ($phase->end === null || $day < $phase->end)) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * The phase the user is in now: the one covering today, else (before the plan starts) the one covering the
     * switch day.
     *
     * @param  list<Phase>  $phases
     */
    public static function current(array $phases, PlanWeekGrid $grid, DateTimeImmutable $today): ?Phase
    {
        return self::covering($today, $phases) ?? self::covering(self::day($grid, $today), $phases);
    }

    /**
     * @param  list<Phase>  $phases
     * @param  ?DateTimeImmutable  $end  the first day after the new phase; null for no end (PH-005)
     * @param  string  $newId  the new phase's id, used only when a phase is added
     * @return list<PhaseWrite>
     */
    public static function writes(
        array $phases, DateTimeImmutable $from, PhaseType $type, float $ratePercentPerWeek, ?DateTimeImmutable $end,
        string $newId,
    ): array {
        $covering = self::covering($from, $phases);
        if ($covering === null) {
            return [new PhaseWrite(new Phase($newId, $type, $from, $end, $ratePercentPerWeek), exists: false)];
        }
        if ($covering->start == $from) {
            return [new PhaseWrite(
                new Phase($covering->id, $type, $from, $end, $ratePercentPerWeek, $covering->eventDate), exists: true)];
        }

        $eventMoves = $covering->eventDate !== null && $covering->eventDate >= $from;
        $closed = new Phase(
            $covering->id, $covering->type, $covering->start, $from, $covering->targetRatePercentPerWeek,
            $eventMoves ? null : $covering->eventDate);

        return [
            new PhaseWrite($closed, exists: true),
            new PhaseWrite(
                new Phase($newId, $type, $from, $end, $ratePercentPerWeek, $eventMoves ? $covering->eventDate : null),
                exists: false),
        ];
    }

    /**
     * SAFE-017, PLAN-007: a nutrition user who now fails moves to training only from the switch day: the type of the
     * phase they are in (Maintenance with none) with no rate, for the rest of that phase, its end included, so the
     * phases after it keep their place and a phase with no end keeps running. With no phase on the switch day, it is
     * PLAN-007's one default mesocycle (TBL-020), ending no later than the next phase starts.
     *
     * @param  list<Phase>  $phases
     * @return list<PhaseWrite>
     */
    public static function toTrainingOnly(
        array $phases, PlanWeekGrid $grid, DateTimeImmutable $today, ExperienceLevel $experience, string $newId,
    ): array {
        $day = self::day($grid, $today);
        $week = $grid->weekIndexContaining($day) ?? 0;
        $type = self::current($phases, $grid, $today)->type ?? PhaseType::Maintenance;
        $default = PlanRules::trainingOnlyPhase($newId, $type, $week, $experience, $grid);
        $covering = self::covering($day, $phases);
        $end = $covering !== null ? $covering->end : self::beforeNextPhase($default->end ?? $grid->start($week + 1), $phases, $day);

        return self::writes($phases, $day, $type, $default->targetRatePercentPerWeek, $end, $newId);
    }

    /** @param  list<Phase>  $phases */
    private static function beforeNextPhase(DateTimeImmutable $end, array $phases, DateTimeImmutable $day): DateTimeImmutable
    {
        foreach ($phases as $phase) {
            if ($phase->start > $day && $phase->start < $end) {
                $end = $phase->start;
            }
        }

        return $end;
    }
}
