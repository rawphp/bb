<?php

namespace App\Capabilities\Nutrition\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** FOOD-030: what meal_food_add would make of the basket: each pick's amount, and the meal's bars, badge and hints after it. */
final class MealFoodAddShowOutput extends CapabilityData
{
    /**
     * @param  list<BasketAmount>  $entries
     * @param  list<MacroBar>  $bars
     * @param  list<MealHint>  $hints
     */
    public function __construct(
        #[Field(items: BasketAmount::class, description: 'One per entry sent, in its order')]
        public readonly array $entries,
        #[Field(items: MacroBar::class, description: 'Calories, protein, fat and carbs after Add (MEAL-001)')]
        public readonly array $bars,
        #[Field(description: 'MEAL-003, after Add')]
        public readonly ?Status $targets_met,
        #[Field(items: MealHint::class, description: 'MEAL-007, after Add')]
        public readonly array $hints,
    ) {}
}
