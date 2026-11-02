<?php

namespace App\Domain\Phases;

use App\Domain\Defaults\TBL050OnTargetBand;
use App\Domain\Model\ClosedRange;

/** PH-037: maintenance after fat loss holds the new lower weight. */
final class MaintenanceHold
{
    /** Centred on the trend weight at the start of maintenance, not the pre-diet weight. */
    public static function corridor(float $startTrendWeight): ClosedRange
    {
        $margin = $startTrendWeight * TBL050OnTargetBand::MAINTENANCE_CORRIDOR_PERCENT_OF_START_TREND_WEIGHT / 100;

        return new ClosedRange($startTrendWeight - $margin, $startTrendWeight + $margin);
    }
}
