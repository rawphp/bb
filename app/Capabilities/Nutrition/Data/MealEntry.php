<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A food or recipe serving in the meal (MEAL-001, MEAL-004, MEAL-005, RCP-002, FOOD-005). */
final class MealEntry extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        #[Field(enum: ['food', 'recipe'])]
        public readonly string $kind,
        public readonly string $name,
        #[Field(description: '105 g (about 2.6 slices), or 1.5 servings')]
        public readonly string $amount_text,
        #[Field(description: 'The amount the amount sheet opens with, in amount_unit')]
        public readonly float $amount,
        #[Field(enum: ['g', 'oz', 'servings'])]
        public readonly string $amount_unit,
        #[Field(description: 'The food\'s first household unit ("slice"), the other unit the amount sheet takes')]
        public readonly ?string $household_unit,
        #[Field(description: 'One of: raw, cooked; or null')]
        public readonly ?string $weighing,
        public readonly Macros $macros,
        public readonly bool $is_locked,
    ) {}
}
