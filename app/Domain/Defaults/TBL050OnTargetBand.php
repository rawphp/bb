<?php

namespace App\Domain\Defaults;

/**
 * TBL-050 On-target band for the weekly review. A week is on target when the measured rate is within the absolute
 * band or the relative band, whichever is wider.
 */
final class TBL050OnTargetBand
{
    /** Plus or minus, percent bodyweight per week. */
    public const ABSOLUTE_PERCENT_BODYWEIGHT_PER_WEEK = 0.15;

    /** Plus or minus, percent of the target rate. */
    public const RELATIVE_PERCENT_OF_TARGET_RATE = 25.0;

    /** Maintenance (REV-029): trend weight within plus or minus this percent of the trend weight at phase start. */
    public const MAINTENANCE_CORRIDOR_PERCENT_OF_START_TREND_WEIGHT = 1.0;
}
