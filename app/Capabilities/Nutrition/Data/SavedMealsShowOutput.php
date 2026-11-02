<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** saved_meals_show: the Saved Meals list and picker (MEAL-070, MEAL-071). */
final class SavedMealsShowOutput extends CapabilityData
{
    public function __construct(
        /** @var list<SavedMealRow> */
        #[Field(items: SavedMealRow::class)]
        public readonly array $meals,
        public readonly ?string $empty_text,
    ) {}
}
