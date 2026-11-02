<?php

namespace App\Domain\Pantry;

use App\Domain\Model\DietFilter;
use App\Domain\Model\MealTarget;

/** A recipe on the Pantry's Recipes filter (FOOD-020, RCP-020): one serving's macros and the diets every ingredient passes. */
final readonly class PantryRecipe
{
    /** @param  list<DietFilter>  $diets */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $mealType,
        public MealTarget $perServing,
        public bool $isLibrary,
        public array $diets = [],
    ) {}
}
