<?php

namespace App\Domain\Macros;

use App\Domain\Model\DayType;

/** One day's macro target. */
final readonly class DayTarget
{
    /**
     * @param  float  $carbsG  total carbs including fiber, as listed on labels (NUT-017)
     */
    public function __construct(
        public DayType $type,
        public bool $isCompleted,
        public float $proteinG,
        public float $fatG,
        public float $carbsG,
        public ?CarbBoundsWarning $carbBoundsWarning,
    ) {}

    /** Calories from every macro (NUT-018). */
    public function kcal(): float
    {
        return MacroRules::kcal(proteinG: $this->proteinG, carbsG: $this->carbsG, fatG: $this->fatG);
    }
}
