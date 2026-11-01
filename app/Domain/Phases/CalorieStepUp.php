<?php

namespace App\Domain\Phases;

use App\Domain\Model\ClosedRange;

/** PH-044: calories step back up after fat loss. */
final class CalorieStepUp
{
    public const WEEKS_PER_STEP = 3;

    public const EXPLANATION = 'A 12-week diet is a 16-week process';

    public static function stepRange(): ClosedRange
    {
        return new ClosedRange(250.0, 500.0);
    }

    public static function clampedStep(float $kcal): float
    {
        $range = self::stepRange();

        return (float) min(max($kcal, $range->lower), $range->upper);
    }

    /**
     * Target for a 0-based week of maintenance: the last cut target plus a step, and another step every 3 weeks,
     * never above measured maintenance.
     */
    public static function targetKcal(
        int $week, float $lastFatLossKcal, float $maintenanceKcal, float $stepKcal, CalorieStepUpMode $mode,
    ): float {
        if ($mode === CalorieStepUpMode::StraightToMaintenance) {
            return $maintenanceKcal;
        }

        $steps = 1 + intdiv($week, self::WEEKS_PER_STEP);

        return min($maintenanceKcal, $lastFatLossKcal + self::clampedStep($stepKcal) * $steps);
    }
}
