<?php

namespace App\Ai;

use App\Models\Food;

/** An ingredient the AI listed and the database food it matched, or null (FOOD-043, FOOD-044, RCP-003, RCP-010). */
final readonly class MatchedItem
{
    public function __construct(
        /** What the AI called it */
        public string $name,
        /** Rounded to one place */
        public float $grams,
        public ?Food $food,
    ) {}
}
