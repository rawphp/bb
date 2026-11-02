<?php

namespace App\Capabilities\Nutrition;

use App\Ai\AiRecipe;
use App\Ai\MatchedItem;
use App\Capabilities\Nutrition\Data\RecipeDraft;
use App\Capabilities\Nutrition\Data\RecipeDraftItem;
use App\Domain\Recipe\RecipeEdit;

/**
 * The drafted recipe as recipe_generate and recipe_import return it, ready to confirm (RCP-003, RCP-010): each item
 * names the food it matched, or "No match".
 */
final class RecipeDrafts
{
    /**
     * @template T of RecipeDraft
     *
     * @param  class-string<T>  $class
     * @param  string|null  $mealType  the one a generated recipe was asked for; an import has none, so the user picks it
     * @return T
     */
    public static function of(string $class, AiRecipe $recipe, ?string $mealType): RecipeDraft
    {
        return new $class(
            name: $recipe->name,
            servings: RecipeEdit::draftServings($recipe->servings),
            meal_type: $mealType,
            steps: RecipeEdit::steps($recipe->steps),
            items: array_map(fn (MatchedItem $item): RecipeDraftItem => new RecipeDraftItem(
                name: $item->name,
                grams: $item->grams,
                food_id: $item->food?->id,
                match_text: $item->food === null ? ScanDrafts::NO_MATCH : $item->food->name,
                detail: $item->food?->brand,
            ), $recipe->items),
        );
    }
}
