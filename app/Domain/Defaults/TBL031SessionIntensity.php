<?php

namespace App\Domain\Defaults;

use App\Domain\Model\ClosedRange;
use App\Domain\Model\DayType;

/** TBL-031 Session intensity from planned working sets. */
final class TBL031SessionIntensity
{
    /** Under this many working sets the session is Light. */
    public const LIGHT_BELOW_SETS = 10;

    /** This many working sets or more make it Hard. */
    public const HARD_FROM_SETS = 20;

    /** An estimated length over this many minutes also makes it Hard. */
    public const HARD_OVER_ESTIMATED_MINUTES = 75;

    public const LIGHT_INTENSITY = DayType::Light;

    public const MODERATE_INTENSITY = DayType::Moderate;

    public const HARD_INTENSITY = DayType::Hard;

    /** A deload week is Light whatever the sets. */
    public const DELOAD_WEEK_INTENSITY = DayType::Light;

    /** This many working sets make it Moderate. */
    public static function moderateSets(): ClosedRange
    {
        return new ClosedRange(10, 19);
    }
}
