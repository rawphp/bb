<?php

namespace App\Domain\Pantry;

/** A saved meal on the Pantry's Saved Meals filter (FOOD-020, MEAL-071). */
final readonly class PantrySavedMeal
{
    public function __construct(
        public string $id,
        public string $name,
        public int $foodCount,
    ) {}
}
