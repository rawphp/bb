<?php

namespace App\Capabilities\Nutrition\Data;

use App\Capabilities\Reads\Data\Today\Bar;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A meal on the Nutrition tab (NAV-001, NAV-005): name, time, planned against target calories, and its four macro bars. */
final class NutritionMeal extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $name,
        public readonly ?string $time_text,
        #[Field(description: '550 / 1040 kcal')]
        public readonly string $kcal_text,
        public readonly MealCheckIn $check_in,
        /** @var list<Bar> */
        #[Field(items: Bar::class, description: 'NAV-005: calories, protein, fat and carbs against target: what was eaten once checked in, else the planned food')]
        public readonly array $bars,
    ) {}
}
