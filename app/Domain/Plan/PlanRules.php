<?php

namespace App\Domain\Plan;

use App\Domain\Defaults\TBL020MesocycleLength;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan;
use DateTimeImmutable;

/** Laying phases on the plan-week grid (PLAN-002, PLAN-006, PLAN-007, PLAN-008, SCH-060). */
final class PlanRules
{
    /**
     * The weeks a phase occupies. An open-ended phase runs to PHP_INT_MAX. Null if the phase does not start on a
     * week boundary.
     */
    public static function weeks(Phase $phase, PlanWeekGrid $grid): ?WeekRange
    {
        $startWeek = $grid->isBoundary($phase->start) ? $grid->weekIndexContaining($phase->start) : null;
        if ($startWeek === null) {
            return null;
        }
        if ($phase->end === null) {
            return new WeekRange($startWeek, PHP_INT_MAX);
        }
        // end is exclusive: a boundary ends the week before it; a mid-week end still takes in its week.
        $endWeek = ($grid->weekIndexContaining($phase->end) ?? $startWeek) + ($grid->isBoundary($phase->end) ? 0 : 1);

        return new WeekRange($startWeek, max($startWeek, $endWeek));
    }

    /**
     * PLAN-006: add a phase at the end of the plan. It starts where the last phase ends (or at the plan start).
     * A null rate is stored as 0 (training-only, PLAN-007).
     *
     * @throws PhaseRefused
     */
    public static function appending(
        string $id, PhaseType $type, ?int $weeks, ?float $ratePercentPerWeek, Plan $plan, PlanWeekGrid $grid,
        int $dietBreakWeeks = 0,
    ): Plan {
        $last = $plan->phases === [] ? null : $plan->phases[count($plan->phases) - 1];
        if ($last !== null && $last->end === null) {
            throw new PhaseRefused(PhaseRefusal::previousPhaseHasNoEnd());
        }
        $start = $last === null ? $grid->start(0) : $last->end;
        $startWeek = $grid->isBoundary($start) ? $grid->weekIndexContaining($start) : null;
        if ($startWeek === null) {
            throw new PhaseRefused(PhaseRefusal::notOnWeekBoundary());
        }
        $refusal = PhaseRules::validate($type, $weeks, $ratePercentPerWeek, $dietBreakWeeks);
        if ($refusal !== null) {
            throw new PhaseRefused($refusal);
        }

        $phase = new Phase(
            id: $id,
            type: $type,
            start: $grid->start($startWeek),
            end: $weeks === null ? null : $grid->start($startWeek + $weeks),
            targetRatePercentPerWeek: $ratePercentPerWeek ?? 0.0,
        );

        return new Plan($plan->id, $plan->start, $plan->reviewWeekday, [...$plan->phases, $phase], $plan->reviewDayChanges);
    }

    /**
     * PLAN-008: change the review day from the first boundary after `$requestedOn`. Week indices do not change:
     * each phase keeps its weeks and moves to the new dates, and weeks before the change keep theirs.
     */
    public static function changingReviewDay(Plan $plan, int $weekday, DateTimeImmutable $requestedOn): Plan
    {
        $old = PlanWeekGrid::forPlan($plan);
        $new = $old->changingReviewDay($weekday, $requestedOn);
        $phases = array_map(function (Phase $phase) use ($old, $new) {
            $range = self::weeks($phase, $old);
            if ($range === null) {
                return $phase;
            }

            return $phase->withDates($new->start($range->lower), $phase->end === null ? null : $new->start($range->upper));
        }, $plan->phases);

        return new Plan($plan->id, $plan->start, $new->startReviewWeekday(), $phases, $new->reviewDayChanges());
    }

    /** PLAN-006: only the current phase and the next one are scheduled in detail; later ones are outlines. */
    public static function isDetailed(int $phaseIndex, int $currentPhaseIndex): bool
    {
        return $phaseIndex <= $currentPhaseIndex + 1;
    }

    /** PLAN-007: a training-only phase has no rate (stored as 0) and is one default mesocycle long (TBL-020). */
    public static function trainingOnlyPhase(
        string $id, PhaseType $type, int $startingAtWeek, ExperienceLevel $experience, PlanWeekGrid $grid,
    ): Phase {
        $length = TBL020MesocycleLength::row($experience)->defaultWeeks;

        return new Phase(
            id: $id,
            type: $type,
            start: $grid->start($startingAtWeek),
            end: $grid->start($startingAtWeek + $length),
            targetRatePercentPerWeek: 0.0,
        );
    }

    /**
     * PLAN-007: a training-only phase repeats until the user changes it: the same phase again, starting where it
     * ends. Null if the phase has no end.
     */
    public static function repeatingTrainingOnlyPhase(Phase $after, PlanWeekGrid $grid, string $id): ?Phase
    {
        $range = self::weeks($after, $grid);
        if ($after->end === null || $range === null) {
            return null;
        }

        return new Phase(
            id: $id,
            type: $after->type,
            start: $after->end,
            end: $grid->start($range->upper + $range->count()),
            targetRatePercentPerWeek: $after->targetRatePercentPerWeek,
        );
    }

    /** PLAN-002: the phase covering a week. */
    public static function phaseIndexCoveringWeek(int $week, Plan $plan, PlanWeekGrid $grid): ?int
    {
        foreach ($plan->phases as $index => $phase) {
            if (self::weeks($phase, $grid)?->contains($week)) {
                return $index;
            }
        }

        return null;
    }

    /** PLAN-002: a mesocycle belongs to the phase covering its first week. */
    public static function owningPhaseIndex(PlannedMesocycle $mesocycle, Plan $plan, PlanWeekGrid $grid): ?int
    {
        return self::phaseIndexCoveringWeek($mesocycle->startWeek, $plan, $grid);
    }

    /**
     * PLAN-002: a mesocycle week follows the rules of the phase covering that week (progression offsets, deload
     * calories), falling back to the owner past the plan's end.
     */
    public static function governingPhaseIndex(int $week, PlannedMesocycle $mesocycle, Plan $plan, PlanWeekGrid $grid): ?int
    {
        return self::phaseIndexCoveringWeek($week, $plan, $grid) ?? self::owningPhaseIndex($mesocycle, $plan, $grid);
    }

    /**
     * SCH-060: where each mesocycle sits once paused weeks are left out of the count. Mesocycles are laid out in
     * start order. One starts where it began, or later by how far the one before it grew (a pause stretches it),
     * never by an overlap that was already there (an ended-early mesocycle) nor by a pause at or after its own raw
     * start (that is its own), and never on a paused week. It then runs until its weeks unpaused weeks are counted.
     * A null entry (a draft, one without dates, or one with no days) has no place. A mesocycle that began before
     * the plan keeps its own weeks: it starts at a negative week.
     *
     * @param  list<?DatedMesocycle>  $mesocycles
     * @param  list<int>  $pausedWeeks
     * @return list<?PlannedMesocycle> in the order of `$mesocycles`
     */
    public static function placements(array $mesocycles, PlanWeekGrid $grid, array $pausedWeeks = []): array
    {
        $paused = array_fill_keys($pausedWeeks, true);
        $raw = [];
        foreach ($mesocycles as $index => $mesocycle) {
            if ($mesocycle !== null) {
                $raw[] = ['index' => $index, 'start' => $grid->weekIndexCountingBack($mesocycle->start), 'weeks' => $mesocycle->weeks];
            }
        }
        usort($raw, fn (array $a, array $b) => [$a['start'], $a['index']] <=> [$b['start'], $b['index']]);

        $result = array_fill(0, count($mesocycles), null);
        $previous = null;
        foreach ($raw as $item) {
            $start = $item['start'];
            if ($previous !== null) {
                // Only the pauses before this one's own first week push it; later ones are its own.
                $from = max($previous['start'], $item['start']);
                $ownPauses = count(array_filter(array_keys($paused), fn (int $week) => $week >= $from && $week < $previous['end']));
                $start = max($start, $previous['end'] - $ownPauses - max(0, $previous['rawEnd'] - $item['start']));
            }
            while (isset($paused[$start])) {
                $start++;
            }
            $end = $start;
            $counted = 0;
            while ($counted < $item['weeks']) {
                if (! isset($paused[$end])) {
                    $counted++;
                }
                $end++;
            }
            $result[$item['index']] = new PlannedMesocycle($start, $item['weeks'], $end - $start - $item['weeks']);
            $previous = ['start' => $start, 'rawEnd' => $item['start'] + $item['weeks'], 'end' => $end];
        }

        return $result;
    }

    /**
     * SCH-060: the week of a mesocycle (from 0) a plan week is, not counting paused weeks since its start.
     *
     * @param  list<int>  $pausedWeeks
     */
    public static function mesocycleWeek(int $planWeek, int $startWeek, array $pausedWeeks): int
    {
        $pausedSince = array_filter(array_unique($pausedWeeks), fn (int $week) => $week >= $startWeek && $week < $planWeek);

        return $planWeek - $startWeek - count($pausedSince);
    }

    /**
     * SCH-060: the plan week a mesocycle week (from 0) falls on; the inverse of mesocycleWeek().
     *
     * @param  list<int>  $pausedWeeks
     */
    public static function planWeek(int $mesocycleWeek, int $startWeek, array $pausedWeeks): int
    {
        $paused = array_fill_keys($pausedWeeks, true);
        $week = $startWeek;
        $counted = 0;
        while (true) {
            if (! isset($paused[$week])) {
                if ($counted === $mesocycleWeek) {
                    return $week;
                }
                $counted++;
            }
            $week++;
        }
    }

    /**
     * PLAN-002: which mesocycle covers a week, as its index in `$mesocycles` and its place on the grid. A null entry
     * (a draft, one without dates, or one with no days) covers nothing. When mesocycles overlap, the one that
     * started last covers, so a new mesocycle takes over from its first week; of two starting the same week, the
     * first listed. SCH-060: places are those of placements(), so paused weeks push later mesocycles out.
     *
     * @param  list<?DatedMesocycle>  $mesocycles
     * @param  list<int>  $pausedWeeks
     */
    public static function coveringMesocycle(int $week, array $mesocycles, PlanWeekGrid $grid, array $pausedWeeks = []): ?CoveringMesocycle
    {
        $covering = null;
        foreach (self::placements($mesocycles, $grid, $pausedWeeks) as $index => $placed) {
            if ($placed === null || ! $placed->contains($week) || $placed->startWeek <= ($covering->placed->startWeek ?? PHP_INT_MIN)) {
                continue;
            }
            $covering = new CoveringMesocycle($index, $placed);
        }

        return $covering;
    }
}
