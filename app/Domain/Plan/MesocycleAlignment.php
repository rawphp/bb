<?php

namespace App\Domain\Plan;

use App\Domain\Defaults\TBL020MesocycleLength;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\PhaseType;

/** Keeping mesocycles, deloads and phase ends lined up (PLAN-020, PLAN-021, PLAN-026, PLAN-027). */
final class MesocycleAlignment
{
    /**
     * PLAN-020: lengths (TBL-020) that fill a phase, closest to the default length. An open-ended phase gets one
     * default mesocycle at a time; the next comes from asking again during the deload (PLAN-027).
     *
     * PLAN-026: in a fat-loss phase, when it holds more than one mesocycle each is 4 to 6 weeks, so deloads (diet
     * breaks) fall 4 to 6 weeks apart. If no mix of lengths makes the phase exactly, the next length up is used and
     * the last mesocycle runs past the phase (endsOnDeload is false; PLAN-021 then offers fixes).
     */
    public static function proposal(PhaseType $type, ?int $phaseWeeks, ExperienceLevel $experience): MesocycleProposal
    {
        $row = TBL020MesocycleLength::row($experience);
        if ($phaseWeeks === null) {
            return new MesocycleProposal([$row->defaultWeeks], false);
        }

        $full = range((int) $row->allowedWeeks->lower, (int) $row->allowedWeeks->upper);
        $spaced = $type->isFatLoss() ? array_values(array_filter($full, fn (int $weeks) => $weeks >= 4 && $weeks <= 6)) : $full;
        rsort($spaced);

        for ($total = max($phaseWeeks, 1); ; $total++) {
            $lengths = self::bestParts($total, $full, $spaced, $row->defaultWeeks);
            if ($lengths !== null) {
                return new MesocycleProposal($lengths, $total === $phaseWeeks);
            }
        }
    }

    /**
     * Mesocycles laid end to end from a week.
     *
     * @return list<PlannedMesocycle>
     */
    public static function mesocycles(MesocycleProposal $proposal, int $startingAtWeek): array
    {
        $start = $startingAtWeek;
        $mesocycles = [];
        foreach ($proposal->lengths as $weeks) {
            $mesocycles[] = new PlannedMesocycle($start, $weeks);
            $start += $weeks;
        }

        return $mesocycles;
    }

    /**
     * PLAN-021: the fixes for a phase whose last week is not a deload. Null when it ends on a deload or in no
     * mesocycle. Both fixes can be null when nothing fits.
     *
     * @param  list<PlannedMesocycle>  $mesocycles
     */
    public static function phaseEndFixes(
        WeekRange $phaseWeeks, PhaseType $type, array $mesocycles, ExperienceLevel $experience,
    ): ?PhaseEndFixes {
        $lastWeek = $phaseWeeks->upper - 1;
        $index = null;
        foreach ($mesocycles as $position => $mesocycle) {
            if ($mesocycle->deloadWeek() === $lastWeek) {
                return null;
            }
            if ($index === null && $mesocycle->contains($lastWeek)) {
                $index = $position;
            }
        }
        if ($index === null) {
            return null;
        }

        $movedEnd = null;
        foreach ($mesocycles as $mesocycle) {
            $deload = $mesocycle->deloadWeek();
            $distance = abs($deload - $lastWeek);
            if ($distance > 2 || ! self::lengthIsAllowed($deload, $phaseWeeks, $type, $mesocycles)) {
                continue;
            }
            // Nearest wins; a tie goes to the later deload.
            if ($movedEnd === null || $distance < abs($movedEnd - $lastWeek) || ($distance === abs($movedEnd - $lastWeek) && $deload > $movedEnd)) {
                $movedEnd = $deload;
            }
        }

        $newWeeks = $lastWeek - $mesocycles[$index]->startWeek + 1;
        $length = TBL020MesocycleLength::row($experience)->allowedWeeks->contains($newWeeks)
            ? new MesocycleLengthChange($index, $newWeeks)
            : null;

        return new PhaseEndFixes($movedEnd === null ? null : $movedEnd + 1, $length);
    }

    /** PLAN-027: "Build your next mesocycle" opens during the deload week. */
    public static function buildsNextMesocycle(int $week, PlannedMesocycle $mesocycle): bool
    {
        return $week === $mesocycle->deloadWeek();
    }

    /**
     * Whether ending the phase on the deload in `$deload` keeps it within TBL-010.
     *
     * @param  list<PlannedMesocycle>  $mesocycles
     */
    private static function lengthIsAllowed(int $deload, WeekRange $phaseWeeks, PhaseType $type, array $mesocycles): bool
    {
        $range = new WeekRange($phaseWeeks->lower, $deload + 1);
        $dietBreaks = $type->isFatLoss()
            ? count(array_filter($mesocycles, fn (PlannedMesocycle $mesocycle) => $range->contains($mesocycle->deloadWeek())))
            : 0;

        return PhaseRules::validate($type, $range->count(), null, $dietBreaks) === null;
    }

    /**
     * The parts (longest first) closest to the default length that sum to `$total`: a single part must be in
     * `$full`; with more parts every part must be in `$spaced`. Ties go to fewer parts, then longer first parts.
     *
     * @param  list<int>  $full
     * @param  list<int>  $spaced  longest first
     * @return ?list<int>
     */
    private static function bestParts(int $total, array $full, array $spaced, int $defaultWeeks): ?array
    {
        $best = null;
        $bestCost = PHP_INT_MAX;

        $consider = function (array $parts) use (&$best, &$bestCost, $defaultWeeks): void {
            $cost = array_sum(array_map(fn (int $part) => abs($part - $defaultWeeks), $parts));
            if ($cost < $bestCost || ($cost === $bestCost && $best !== null && count($parts) < count($best))) {
                $best = $parts;
                $bestCost = $cost;
            }
        };

        $search = function (int $remaining, array $parts, int $maxPart) use (&$search, $consider, $spaced): void {
            if ($remaining === 0) {
                if (count($parts) > 1) {
                    $consider($parts);
                }

                return;
            }
            foreach ($spaced as $part) {
                if ($part <= min($maxPart, $remaining)) {
                    $search($remaining - $part, [...$parts, $part], $part);
                }
            }
        };

        if (in_array($total, $full, true)) {
            $consider([$total]);
        }
        $search($total, [], PHP_INT_MAX);

        return $best;
    }
}
