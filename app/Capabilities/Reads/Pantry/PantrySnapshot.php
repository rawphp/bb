<?php

namespace App\Capabilities\Reads\Pantry;

use App\Domain\Model\DietFilter;
use App\Domain\Pantry\PantryFood;
use App\Domain\Pantry\PantryRecipe;
use App\Domain\Pantry\PantrySavedMeal;

/**
 * What one food_search reads, as plain values ({@see PantryReader} reads it): only what that input shows, so a tab
 * reads its own list and a search reads the search.
 */
final readonly class PantrySnapshot
{
    /**
     * @param  list<DietFilter>  $diets  the user's diet filters (FOOD-027)
     * @param  list<PantryFood>  $foods  the tab's foods before the filters, or a search's results
     * @param  list<string>  $ownIds  a search's results that are in the user's Recents, Favorites or Custom
     * @param  list<string>  $favoriteIds  the shown foods the user favourited
     * @param  array<string, string>  $mealFoods  the foods already in the meal, food id => name, in the meal's order
     * @param  ?string  $suggestion  FOOD-024's corrected query
     * @param  list<PantryRecipe>  $recipes  the user's own recipes, then the library's, that pass the diet filters and the search
     * @param  list<PantrySavedMeal>  $savedMeals  the user's saved meals that match the search
     */
    public function __construct(
        public array $diets = [],
        public array $foods = [],
        public array $ownIds = [],
        public array $favoriteIds = [],
        public array $mealFoods = [],
        public ?string $suggestion = null,
        public array $recipes = [],
        public array $savedMeals = [],
    ) {}
}
