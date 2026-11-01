<?php

namespace App\Domain\Safety;

use App\Domain\Model\PhaseType;

/** What the screening allows (SAFE-001, SAFE-003, SAFE-004). */
final readonly class Eligibility
{
    /**
     * @param  list<ScreeningItem>  $reasons  the items that applied, in the spec's order
     */
    private function __construct(
        public EligibilityMode $mode,
        public array $reasons,
    ) {}

    public static function allTargets(): self
    {
        return new self(EligibilityMode::AllTargets, []);
    }

    /** @param  list<ScreeningItem>  $reasons */
    public static function fatLossBlocked(array $reasons): self
    {
        return new self(EligibilityMode::FatLossBlocked, $reasons);
    }

    /** @param  list<ScreeningItem>  $reasons */
    public static function trainingOnly(array $reasons): self
    {
        return new self(EligibilityMode::TrainingOnly, $reasons);
    }

    public function hasNutritionTargets(): bool
    {
        return $this->mode !== EligibilityMode::TrainingOnly;
    }

    /** SAFE-003, SAFE-004: training, weigh-ins and trend weight stay on in every mode. */
    public function allows(EligibilityFeature $feature): bool
    {
        return $feature === EligibilityFeature::NutritionTargets ? $this->hasNutritionTargets() : true;
    }

    /**
     * Phases that may get calorie targets. Blocking Fat loss blocks Mini-cut too (PLAN-009).
     *
     * @return list<PhaseType>
     */
    public function allowedPhases(): array
    {
        return match ($this->mode) {
            EligibilityMode::AllTargets => PhaseType::cases(),
            EligibilityMode::FatLossBlocked => array_values(array_filter(
                PhaseType::cases(), fn (PhaseType $phase) => ! $phase->isFatLoss())),
            EligibilityMode::TrainingOnly => [],
        };
    }
}
