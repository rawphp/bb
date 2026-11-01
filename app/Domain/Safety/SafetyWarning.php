<?php

namespace App\Domain\Safety;

use App\Domain\Model\PhaseType;

/** A safety warning that never blocks (SAFE-013, SAFE-015, SAFE-018, ONB-021). */
final readonly class SafetyWarning
{
    private function __construct(
        public SafetyWarningKind $kind,
        /** DayFatBelowFloor only. */
        public ?int $minimumG = null,
        /** The meal's name, for the per-meal warnings only. */
        public ?string $meal = null,
    ) {}

    public static function glp1MuscleLoss(): self
    {
        return new self(SafetyWarningKind::Glp1MuscleLoss);
    }

    public static function highStepTarget(): self
    {
        return new self(SafetyWarningKind::HighStepTarget);
    }

    public static function dayFatBelowFloor(int $minimumG): self
    {
        return new self(SafetyWarningKind::DayFatBelowFloor, minimumG: $minimumG);
    }

    public static function mealFatBelowMinimum(string $meal): self
    {
        return new self(SafetyWarningKind::MealFatBelowMinimum, meal: $meal);
    }

    public static function mealCarbsBelowMinimum(string $meal): self
    {
        return new self(SafetyWarningKind::MealCarbsBelowMinimum, meal: $meal);
    }

    public static function maintenanceFirst(): self
    {
        return new self(SafetyWarningKind::MaintenanceFirst);
    }

    public function message(): string
    {
        return match ($this->kind) {
            SafetyWarningKind::Glp1MuscleLoss => "You've eaten under 80% of your target for 2 weeks. On a GLP-1 that risks losing muscle. Try to eat to your target.",
            SafetyWarningKind::HighStepTarget => 'More than 12,000 steps a day can hurt your recovery from lifting.',
            SafetyWarningKind::DayFatBelowFloor => "Fat is under 15% of today's calories. Raise fat to at least {$this->minimumG} g.",
            SafetyWarningKind::MealFatBelowMinimum => "{$this->meal} has under 5 g fat. Add fat to this meal.",
            SafetyWarningKind::MealCarbsBelowMinimum => "{$this->meal} has under 5 g carbs. Add carbs to this meal.",
            SafetyWarningKind::MaintenanceFirst => 'You finished a diet in the last 8 weeks. Start with Maintenance so your body and hunger can recover before cutting again. You can still choose Fat loss.',
        };
    }

    /** The phase the warning recommends instead; null where it recommends none. */
    public function recommendedPhase(): ?PhaseType
    {
        return $this->kind === SafetyWarningKind::MaintenanceFirst ? PhaseType::Maintenance : null;
    }
}
