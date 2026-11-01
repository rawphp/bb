<?php

namespace App\Domain\Macros;

use App\Domain\Model\ClosedRange;
use App\Domain\Model\Phase;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Sex;

/** Rule constants for the macro targets (NUT-010 to NUT-024) and the safety rails they enforce. */
final class MacroRules
{
    public const KCAL_PER_GRAM_PROTEIN = 4.0;

    public const KCAL_PER_GRAM_CARBS = 4.0;

    public const KCAL_PER_GRAM_FAT = 9.0;

    /** NUT-010: protein per kg of lean-adjusted bodyweight. */
    public const PROTEIN_G_PER_KG = 2.2;

    /** NUT-010. */
    public const LEAN_ADJUST_FACTOR = 1.25;

    /** NUT-011: protein multiplier in the first weeks of a muscle-gain phase after fat loss. */
    public const PROTEIN_BOOST_FACTOR = 1.5;

    /** NUT-011: cap on boosted protein, per kg of bodyweight. */
    public const PROTEIN_BOOST_CAP_G_PER_KG = 3.3;

    /** NUT-011: how many weeks the boost lasts. */
    public const PROTEIN_BOOST_WEEKS = 2;

    /** NUT-012: default fat share of the week's average daily calories. */
    public const FAT_SHARE_OF_CALORIES = 0.25;

    /** NUT-012, SAFE-009: fat never below this share of any day's calories. */
    public const FAT_FLOOR_SHARE_OF_CALORIES = 0.15;

    /** NUT-012: fat never below this many grams per kg of lean mass. */
    public const FAT_FLOOR_G_PER_KG_LEAN = 0.66;

    /** NUT-007: energy in one kg of body mass. */
    public const KCAL_PER_KG = 7700.0;

    /** SAFE-005: fastest allowed loss, percent of bodyweight per week. */
    public const MAX_FAT_LOSS_RATE_PERCENT = 1.0;

    /** SAFE-011: at or below this BMI, no further cuts. */
    public const BMI_CUT_FLOOR = 17.0;

    /** SAFE-010: minimum grams of fat and of carbs in every meal. */
    public const MEAL_MINIMUM_G = 5.0;

    /** NUT-016: increases go to carbs until training-day carbs reach this many grams per lb of lean mass. */
    public const INCREASE_CARB_CAP_G_PER_LB_LEAN = 3.0;

    /**
     * NUT-016: past the cap, this share of the extra calories goes to fat and the rest to carbs.
     * The spec says "split between carbs and fat" without a ratio; this reuses the NUT-012 default fat share.
     */
    public const INCREASE_FAT_SHARE_AFTER_CAP = 0.25;

    /**
     * NUT-018, FOOD-060: calories from grams of each macro, 4 per gram of protein and carbs, 9 per gram of fat.
     * Every calorie figure built from macros (a day's target, the day balance, a meal split, a custom food) asks
     * here; nothing else adds the three up.
     */
    public static function kcal(float $proteinG, float $carbsG, float $fatG): float
    {
        return self::KCAL_PER_GRAM_PROTEIN * $proteinG + self::KCAL_PER_GRAM_CARBS * $carbsG + self::KCAL_PER_GRAM_FAT * $fatG;
    }

    /** NUT-010: body fat at or below this uses bodyweight; above it uses lean mass x LEAN_ADJUST_FACTOR. */
    public static function leanAdjustThresholdPercent(Sex $sex): float
    {
        return match ($sex) {
            Sex::Male => 20,
            Sex::Female => 30,
        };
    }

    /** SAFE-005: the phase's target rate, never faster than the fat loss limit. */
    public static function targetRatePercent(Phase $phase): float
    {
        return max($phase->targetRatePercentPerWeek, -self::MAX_FAT_LOSS_RATE_PERCENT);
    }

    /** NUT-007: the daily calorie change for a weekly rate (percent of bodyweight) at a weight. */
    public static function dailyKcal(float $ratePercent, float $weightKg): float
    {
        return $ratePercent / 100 * $weightKg * self::KCAL_PER_KG / PlanWeek::LENGTH_IN_DAYS;
    }

    /** NUT-022: a day may be moved to between 80% and 125% of the week's daily average. */
    public static function moveBand(): ClosedRange
    {
        return new ClosedRange(0.8, 1.25);
    }
}
