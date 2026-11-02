<?php

namespace App\Domain\Progression;

/**
 * CAP-016, PROG-024: a set's rep table. Every weight the exercise's load steps allow within 15% of the set's planned
 * weight (and the planned weight itself, when a carried load is off the steps), each with the reps that keep the
 * planned effort. The view looks a typed weight up here; a weight outside the table only shows the RIR (PROG-025).
 * Weights are in the user's unit.
 */
final class RepTable
{
    /** PROG-025: the table spans this far either side of the planned weight. */
    public const SPAN = LoadProgression::FAR_WEIGHT_CHANGE;

    private const TOLERANCE = 1e-9;

    /**
     * No table without a planned weight and reps, without a target RIR (a deload week), or without a weight to step
     * (bodyweight).
     *
     * @return list<RepTableRow> lightest first
     */
    public static function rows(?float $targetWeight, ?int $targetReps, ?int $rir, LoadRules $rules, bool $isFatLoss): array
    {
        $step = $rules->step($isFatLoss);
        if ($targetWeight === null || $targetWeight <= 0 || $targetReps === null || $rir === null || $step === null) {
            return [];
        }

        $weights = [round($targetWeight, 4)];
        $first = (int) ceil($targetWeight * (1 - self::SPAN) / $step - self::TOLERANCE);
        $last = (int) floor($targetWeight * (1 + self::SPAN) / $step + self::TOLERANCE);
        for ($k = max(1, $first); $k <= $last; $k++) {
            $weights[] = round($k * $step, 4);
        }
        $weights = array_values(array_unique($weights));
        sort($weights);

        $target = new SetTarget($targetWeight, $targetReps, $rir);
        $rows = [];
        foreach ($weights as $weight) {
            $reps = LoadProgression::retarget($target, $weight, null, $rules)->value;
            if ($reps >= 1) {
                $rows[] = new RepTableRow($weight, $reps);
            }
        }

        return $rows;
    }

    /**
     * CAP-016: the reps a table gives for a weight, as the view looks a typed weight up; null for a weight that is not
     * one of its rows, which shows only the RIR.
     *
     * @param  list<RepTableRow>  $table
     */
    public static function repsAt(array $table, float $weight): ?int
    {
        foreach ($table as $row) {
            if (abs($row->weight - $weight) < 0.001) {
                return $row->reps;
            }
        }

        return null;
    }
}
