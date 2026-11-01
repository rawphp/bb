<?php

namespace App\Domain\Defaults;

use App\Domain\Model\ClosedRange;
use App\Domain\Model\DayType;

/**
 * TBL-032 Carb sanity bounds by day type, grams per lb of lean mass. Outside the bounds for 2 weeks running raises
 * a weekly-review warning.
 */
final class TBL032CarbSanityBounds
{
    public const WARN_AFTER_WEEKS_RUNNING = 2;

    /** Null for deload, which the table does not list. */
    public static function gramsPerLbLeanMass(DayType $dayType): ?ClosedRange
    {
        return match ($dayType) {
            DayType::Rest => new ClosedRange(0.5, 1.0),
            DayType::Light, DayType::Moderate, DayType::Hard => new ClosedRange(1.5, 3.0),
            DayType::Deload => null,
        };
    }
}
