<?php

namespace App\Domain\Progress;

use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;

/** ANA-010: what goes out with the progress image. */
final class ProgressShare
{
    public const TITLE = 'My progress';

    /** "Fat loss: 82.0 kg to 80.4 kg (-1.6 kg) over 3 weeks": the phase, start to current trend, change, weeks. */
    public static function text(ProgressSummary $summary, WeightUnit $unit): string
    {
        // Rounded, so a day of 23 or 25 hours around daylight saving still counts as one day.
        $days = (int) round(($summary->currentDay->getTimestamp() - $summary->startDay->getTimestamp()) / 86400);
        $weeks = max(1, intdiv($days, 7));

        return sprintf(
            '%s: %s to %s (%s) over %d %s',
            $summary->phaseType?->title() ?? 'Progress',
            self::weight('%.1f', $summary->startKg, $unit),
            self::weight('%.1f', $summary->currentKg, $unit),
            self::weight('%+.1f', $summary->changeKg(), $unit),
            $weeks,
            $weeks === 1 ? 'week' : 'weeks',
        );
    }

    /** One decimal in the user's unit, for example "81.4 kg" (rp-clone's WeightDisplay). */
    private static function weight(string $format, float $kg, WeightUnit $unit): string
    {
        $value = $unit === WeightUnit::Kg ? $kg : UnitConversion::kgToLb($kg);

        return sprintf("{$format} %s", $value, $unit->value);
    }
}
