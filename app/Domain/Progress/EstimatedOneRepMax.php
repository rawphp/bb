<?php

namespace App\Domain\Progress;

/** ANA-009: the estimated one rep max (e1RM) a set of an exercise's history is ranked by. */
final class EstimatedOneRepMax
{
    /** Two estimates closer than this are one. */
    private const TOLERANCE = 1e-9;

    /** Weight times one plus reps over 30, to 2 decimals; a missing weight or missing reps count as 0. */
    public static function kg(?float $weightKg, ?int $reps): float
    {
        return round(($weightKg ?? 0) * (1 + ($reps ?? 0) / 30), 2);
    }

    /**
     * A session's best: the highest estimate of its sets, 0 without any.
     *
     * @param  list<float>  $estimates
     */
    public static function best(array $estimates): float
    {
        return $estimates === [] ? 0.0 : max($estimates);
    }

    /**
     * Which set wears the best pill: the first one that reaches the session's best. null without sets.
     *
     * @param  list<float>  $estimates
     */
    public static function bestIndex(array $estimates): ?int
    {
        $best = self::best($estimates);
        foreach ($estimates as $index => $estimate) {
            if (abs($estimate - $best) < self::TOLERANCE) {
                return $index;
            }
        }

        return null;
    }
}
