<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** saved_meal_rename: rename a saved meal (MEAL-071). */
final class SavedMealRenameInput extends CapabilityData
{
    public function __construct(
        public readonly string $saved_meal_id,
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $name,
    ) {}
}
