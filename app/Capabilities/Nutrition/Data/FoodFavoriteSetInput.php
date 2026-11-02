<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** food_favorite_set: favourite a food or take it back (FOOD-022). */
final class FoodFavoriteSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $food_id,
        public readonly bool $favorite,
    ) {}
}
