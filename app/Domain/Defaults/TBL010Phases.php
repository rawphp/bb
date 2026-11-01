<?php

namespace App\Domain\Defaults;

use App\Domain\Defaults\TBL010Phases\RatePreset;
use App\Domain\Defaults\TBL010Phases\Row;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\PhaseType;

/**
 * TBL-010 Phases: rate, presets and length limits per phase type.
 * Rates are signed, in percent bodyweight per week: positive gains, negative loses (a fat-loss -0.75 means losing
 * 0.75%). Phase::$targetRatePercentPerWeek uses the same sign.
 */
final class TBL010Phases
{
    /** Gain to mini-cut length should be at least 2:1. */
    public const MINIMUM_GAIN_TO_MINI_CUT_LENGTH_RATIO = 2.0;

    /** Under this many weeks the app suggests a Mini-cut instead of fat loss. */
    public const MINI_CUT_SUGGESTED_BELOW_FAT_LOSS_WEEKS = 6;

    /** Over this many weeks a fat-loss phase shows a warning. */
    public const FAT_LOSS_WARN_ABOVE_WEEKS = 12;

    /** A single fat-loss phase should not plan to lose more than this share of starting bodyweight. */
    public const MAX_FAT_LOSS_PERCENT_OF_START_BODYWEIGHT = 10.0;

    public static function row(PhaseType $type): Row
    {
        return match ($type) {
            PhaseType::FatLoss => self::fatLoss(),
            PhaseType::MuscleGain => self::muscleGain(),
            PhaseType::Maintenance => self::maintenance(),
            PhaseType::MiniCut => self::miniCut(),
        };
    }

    public static function fatLoss(): Row
    {
        return new Row(
            rateRangePercentPerWeek: new ClosedRange(-1.0, -0.25),
            presets: [
                new RatePreset('Highest success', -0.75),
                new RatePreset('Slow & steady', -0.5),
                new RatePreset('Custom', null),
            ],
            minWeeks: 2, maxWeeks: 16, recommendedWeeks: new ClosedRange(6, 12),
        );
    }

    public static function muscleGain(): Row
    {
        return new Row(
            rateRangePercentPerWeek: new ClosedRange(0.125, 0.5),
            presets: [new RatePreset('Custom', null)],
            minWeeks: 4, maxWeeks: 16, recommendedWeeks: new ClosedRange(8, 16),
        );
    }

    public static function maintenance(): Row
    {
        return new Row(
            rateRangePercentPerWeek: new ClosedRange(-0.25, 0.25),
            presets: [new RatePreset('Hold', 0.0)],
            minWeeks: 2, maxWeeks: null, recommendedWeeks: null,
        );
    }

    public static function miniCut(): Row
    {
        return new Row(
            rateRangePercentPerWeek: new ClosedRange(-1.0, -0.75),
            presets: [new RatePreset('Default', -1.0)],
            minWeeks: 2, maxWeeks: 6, recommendedWeeks: null,
        );
    }

    /** Muscle-gain presets "recommended by experience". */
    public static function muscleGainRatePercentPerWeek(ExperienceLevel $experience): float
    {
        return match ($experience) {
            ExperienceLevel::Beginner => 0.5,
            ExperienceLevel::Intermediate => 0.375,
            ExperienceLevel::Advanced => 0.25,
        };
    }

    /** The book's recommended muscle-gain length (the app recommends 8 to 16). */
    public static function muscleGainBookRecommendedWeeks(): ClosedRange
    {
        return new ClosedRange(6, 16);
    }

    /** After a cut, maintenance should last 2/3 to 1x the cut length. */
    public static function maintenanceAfterCutLengthFactor(): ClosedRange
    {
        return new ClosedRange(2.0 / 3.0, 1.0);
    }
}
