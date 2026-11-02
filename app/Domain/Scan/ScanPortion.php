<?php

namespace App\Domain\Scan;

/**
 * FOOD-045: the portion control that scales the whole scanned meal, and the one rule for a scanned
 * food's grams: to 0.1 g and never below 0.1 g. The rows, the merge per food and the totals use it.
 * Each row keeps its grams at 1× unrounded (its base), as rp-clone's ScanModel does, so the shown grams
 * are rounded once and stepping the portion down and back up returns the same amount.
 */
final class ScanPortion
{
    public const STEP = 0.25;

    public const MIN = 0.25;

    public const MAX = 4.0;

    public const MIN_GRAMS = 0.1;

    /** Steps are quarters, so a sum like 3.75 + 0.25 lands exactly; this absorbs input like 1.1 and float noise. */
    private const EPSILON = 1e-9;

    public static function grams(float $grams): float
    {
        return max(self::MIN_GRAMS, round($grams * 10) / 10);
    }

    /**
     * A row's grams at 1×, from what the screen sent back: the base it was given when `grams` is still what
     * that base shows at portion `from`, else the amount the user typed, which is the amount shown at `from`.
     */
    public static function base(float $grams, ?float $baseGrams, float $from): float
    {
        if ($baseGrams !== null && abs(self::grams($baseGrams * $from) - $grams) < self::EPSILON) {
            return $baseGrams;
        }

        return $grams / $from;
    }

    /** "1×", "1.25×". */
    public static function text(float $portion): string
    {
        return sprintf('%g×', $portion);
    }

    /** The next larger portion, or null at the largest. */
    public static function up(float $portion): ?float
    {
        $next = $portion + self::STEP;

        return $next <= self::MAX + self::EPSILON ? $next : null;
    }

    /** The next smaller portion, or null at the smallest. */
    public static function down(float $portion): ?float
    {
        $next = $portion - self::STEP;

        return $next >= self::MIN - self::EPSILON ? $next : null;
    }
}
