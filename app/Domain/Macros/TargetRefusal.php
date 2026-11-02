<?php

namespace App\Domain\Macros;

use DomainException;

/** A cut the app will not turn into a target (SAFE-011, SAFE-012). */
final class TargetRefusal extends DomainException
{
    private function __construct(
        public readonly TargetRefusalReason $reason,
        public readonly ?float $bmi = null,
        public readonly ?int $dayIndex = null,
    ) {
        parent::__construct(match ($reason) {
            TargetRefusalReason::BmiAtOrBelowFloor => "BMI {$bmi} is at or below the floor for cuts.",
            TargetRefusalReason::BreaksFloor => "The cut breaks the fat or carb floor on day {$dayIndex}.",
        });
    }

    /** SAFE-011. */
    public static function bmiAtOrBelowFloor(float $bmi): self
    {
        return new self(TargetRefusalReason::BmiAtOrBelowFloor, bmi: $bmi);
    }

    /** SAFE-012. */
    public static function breaksFloor(int $dayIndex): self
    {
        return new self(TargetRefusalReason::BreaksFloor, dayIndex: $dayIndex);
    }

    public function recommendation(): TargetRecommendation
    {
        return match ($this->reason) {
            TargetRefusalReason::BmiAtOrBelowFloor => TargetRecommendation::RaiseToMaintenance,
            TargetRefusalReason::BreaksFloor => TargetRecommendation::RaiseCaloriesOrEndPhase,
        };
    }
}
