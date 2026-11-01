<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Calories and the three macros, each ready to show with its unit (CAP-015, UI-011). */
final class MacroLine extends CapabilityData
{
    public function __construct(
        #[Field(description: '"512 kcal"')] public readonly string $kcal,
        #[Field(description: '"P 40"')] public readonly string $protein,
        #[Field(description: '"F 12"')] public readonly string $fat,
        #[Field(description: '"C 60"')] public readonly string $carbs,
        #[Field(description: 'The line read aloud: "512 kcal, 40 grams protein, 12 grams fat, 60 grams carbs".')] public readonly string $label,
    ) {}
}
