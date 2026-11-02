<?php

namespace App\Domain\Phases;

/** PH-035: where a new phase's starting calories come from. */
final readonly class StartingCalories
{
    /** PH-035: previous phases shorter than this give no valid measurement. */
    public const MINIMUM_PREVIOUS_PHASE_WEEKS = 3;

    private function __construct(
        public StartingCaloriesSource $source,
        public float $kcal,
    ) {}

    public static function measured(float $kcal): self
    {
        return new self(StartingCaloriesSource::Measured, $kcal);
    }

    public static function formula(float $kcal): self
    {
        return new self(StartingCaloriesSource::Formula, $kcal);
    }

    /**
     * Measured expenditure (REV-021) unless there is none, the previous phase was short, or the user is
     * training-only; then the formula (NUT-002).
     */
    public static function resolve(?float $measuredKcal, int $previousPhaseWeeks, bool $isTrainingOnly, float $formulaKcal): self
    {
        if ($measuredKcal === null || $previousPhaseWeeks < self::MINIMUM_PREVIOUS_PHASE_WEEKS || $isTrainingOnly) {
            return self::formula($formulaKcal);
        }

        return self::measured($measuredKcal);
    }
}
