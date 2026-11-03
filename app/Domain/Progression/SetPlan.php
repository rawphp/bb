<?php

namespace App\Domain\Progression;

/**
 * The targets of one exercise's sets (WO-004, WO-006, PROG-020 to PROG-027, PROG-041, MESO-061, SCH-063). The caller gathers
 * last week's sets and the carried load; this decides. Weights are in the user's unit throughout.
 */
final class SetPlan
{
    /** @return list<PlannedLoad> */
    public static function targets(SetPlanInputs $inputs): array
    {
        $count = max(0, $inputs->sets);

        // PROG-041: a deload repeats last week at half the reps, and half the weight in the second half of the week.
        if ($inputs->deloadHalf !== null) {
            $step = $inputs->rules->step($inputs->isFatLoss);
            $planned = [];
            for ($index = 0; $index < $count; $index++) {
                $base = self::lastWeekFor($inputs->lastWeek, $index);
                if ($base === null) {
                    return self::empty($count);
                }
                $weight = null;
                if ($base->weight !== null) {
                    $weight = Deload::weight($base->weight, $inputs->deloadHalf);
                    // Half of a loaded weight is rarely one the equipment can make: use the nearest step.
                    if ($inputs->deloadHalf === DeloadHalf::Second && $step !== null && $step > 0) {
                        $weight = round($weight / $step) * $step;
                    }
                }
                $planned[] = new PlannedLoad($weight, max(1, Deload::reps($base->reps)));
            }

            return $planned;
        }

        $rir = $inputs->rir ?? 3;

        // PROG-020: from week 2 on, last week's sets set this week's. SCH-063: after a rough week they are repeated as
        // logged, with no rise in weight or reps (PROG-021, PROG-022, PROG-027).
        if ($inputs->lastWeek !== []) {
            $targets = $inputs->lastWeekWasRough
                ? array_map(fn (SetLoad $set) => new SetTarget($set->weight, $set->reps, $rir), $inputs->lastWeek)
                : LoadProgression::targets($inputs->lastWeek, $rir, $inputs->isFatLoss, $inputs->rules);
            $last = $targets[count($targets) - 1];
            $planned = [];
            for ($index = 0; $index < $count; $index++) {
                if ($index < count($targets)) {
                    $planned[] = new PlannedLoad($targets[$index]->weight, $targets[$index]->reps);

                    continue;
                }
                // An added set drops reps like the sets before it (PROG-026).
                $extra = $index - count($targets) + 1;
                $planned[] = new PlannedLoad($last->weight, max(1, $last->reps - LoadProgression::WEEK_ONE_DROP * $extra));
            }

            return $planned;
        }

        // MESO-061, PROG-026: a copied mesocycle starts from the old week 1.
        if ($inputs->carried !== null) {
            $start = LoadProgression::copiedStart($inputs->carried, $inputs->experience, $inputs->isFatLoss, $inputs->rules);

            return array_map(
                fn (SetTarget $target) => new PlannedLoad($target->weight, $target->reps),
                LoadProgression::firstWeekTargets($start->weight, $start->reps, $count, $rir),
            );
        }

        // WO-006: no loads to build on, so the weights are empty and the reps show the RIR.
        return self::empty($count);
    }

    /**
     * The set last week that set $index (0-based) builds on: its own, else last week's last; null when last week has
     * none.
     *
     * @param  list<SetLoad>  $lastWeek
     */
    public static function lastWeekFor(array $lastWeek, int $index): ?SetLoad
    {
        return $lastWeek === [] ? null : $lastWeek[min($index, count($lastWeek) - 1)];
    }

    /** @return list<PlannedLoad> */
    private static function empty(int $count): array
    {
        return array_fill(0, $count, new PlannedLoad(null, null));
    }
}
