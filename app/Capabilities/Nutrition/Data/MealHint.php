<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** MEAL-007: a macro the chosen foods cannot reach, and the curated group the Pantry opens on. */
final class MealHint extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        #[Field(enum: ['protein_recommended', 'protein_sparingly', 'protein_before_bed', 'veggies', 'carbs_recommended', 'carbs_sparingly', 'fat_recommended', 'fat_sparingly'])]
        public readonly string $group,
    ) {}
}
