<?php

namespace App\Capabilities\Reads\Progress;

use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use DateTimeImmutable;

/** How the Progress screens say things (rp-clone's ProgressCards, ProgressTabView and ExerciseHistoryViews). */
final class ProgressWords
{
    /** A weight in the user's unit, for a chart point. */
    public static function inUnit(float $kg, WeightUnit $unit): float
    {
        return $unit === WeightUnit::Kg ? $kg : UnitConversion::kgToLb($kg);
    }

    /** "6 Jul 2026 – 30 Aug 2026" from the first day and the day after the last. */
    public static function dates(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return $start->format('j M Y').' – '.$end->modify('-1 day')->format('j M Y');
    }
}
