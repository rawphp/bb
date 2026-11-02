<?php

namespace App\Domain\Scan;

/**
 * FOOD-045: the portion control that scales the whole scanned meal, and the one rule for a scanned
 * food's grams: to 0.1 g and never below 0.1 g. The rows, the merge per food and the totals use it.
 */
final class ScanPortion
{
    public const STEP = 0.25;

    public const MIN = 0.25;

    public const MAX = 4.0;

    public const MIN_GRAMS = 0.1;

    /** Steps are quarters, so a sum like 3.75 + 0.25 lands exactly; this only absorbs input like 1.1. */
    private const EPSILON = 1e-9;

    public static function grams(float $grams): float
    {
        return max(self::MIN_GRAMS, round($grams * 10) / 10);
    }

    /** Grams shown at portion `from`, shown again at portion `to`. */
    public static function rescale(float $grams, float $from, float $to): float
    {
        return self::grams($grams / $from * $to);
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
