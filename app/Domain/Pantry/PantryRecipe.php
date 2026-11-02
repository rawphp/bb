<?php

namespace App\Domain\Pantry;

use App\Domain\Model\MealTarget;

/** A recipe on the Pantry's Recipes filter (FOOD-020, RCP-020), with one serving's macros. */
final readonly class PantryRecipe
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $mealType,
        public MealTarget $perServing,
        public bool $isLibrary,
    ) {}
}
