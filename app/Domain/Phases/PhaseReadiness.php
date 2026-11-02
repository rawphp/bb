<?php

namespace App\Domain\Phases;

use App\Domain\Defaults\TBL032CarbSanityBounds;
use App\Domain\Model\DayType;
use App\Domain\Model\PhaseType;
use App\Domain\Model\UnitConversion;

/** PH-046: readiness before a new fat loss, mini-cut or muscle gain. */
final readonly class PhaseReadiness
{
    public const MINIMUM_FAT_GRAMS_PER_LB_LEAN = 0.3;

    /** @param  list<ReadinessFailure>  $failures */
    public function __construct(
        public array $failures,
    ) {}

    /** The hard training day's lower carb bound (TBL-032). */
    public static function minimumCarbGramsPerLbLean(): float
    {
        return (float) TBL032CarbSanityBounds::gramsPerLbLeanMass(DayType::Hard)?->lower;
    }

    /** Any failure warns and recommends maintenance first. */
    public function recommendsMaintenanceFirst(): bool
    {
        return $this->failures !== [];
    }

    public static function check(
        PhaseType $newPhase, bool $dietFatigueLow, bool $trainingFatigueLow, bool $calendarClear,
        float $dailyFatGrams, float $trainingDayCarbGrams, float $leanMassKg,
    ): self {
        if ($newPhase === PhaseType::Maintenance) {
            return new self([]);
        }

        $leanLb = UnitConversion::kgToLb($leanMassKg);
        $failures = [];
        if (! $dietFatigueLow) {
            $failures[] = ReadinessFailure::DietFatigueHigh;
        }
        if (! $trainingFatigueLow) {
            $failures[] = ReadinessFailure::TrainingFatigueHigh;
        }
        if (! $calendarClear) {
            $failures[] = ReadinessFailure::CalendarNotClear;
        }
        if ($dailyFatGrams < self::MINIMUM_FAT_GRAMS_PER_LB_LEAN * $leanLb) {
            $failures[] = ReadinessFailure::FatTooLow;
        }
        if ($trainingDayCarbGrams < self::minimumCarbGramsPerLbLean() * $leanLb) {
            $failures[] = ReadinessFailure::CarbsTooLow;
        }

        return new self($failures);
    }
}
