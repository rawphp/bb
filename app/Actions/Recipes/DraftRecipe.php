<?php

namespace App\Actions\Recipes;

use App\Ai\AiRecipe;
use App\Ai\FoodAi;
use App\Ai\PageUnavailable;
use App\Ai\RecipeBrief;
use App\Domain\Model\DietFilter;
use App\Models\User;

/**
 * RCP-010, RCP-003: a recipe drafted by the AI from a brief, or read from a web page, its items matched to foods.
 * The recipe_generate and recipe_import capabilities call it from run(). Nothing is saved.
 */
final class DraftRecipe
{
    public function __construct(private readonly FoodAi $ai) {}

    /**
     * RCP-010: macros are per serving. The profile's diet filters apply, to the recipe and to the foods it is matched to.
     *
     * @param  list<string>  $ingredients  to use where they fit
     */
    public function generate(User $user, float $protein, float $fat, float $carbs, ?float $kcal, string $mealType, string $complexity, array $ingredients, ?string $preferences): AiRecipe
    {
        $preferences = trim((string) $preferences);

        return $this->ai->recipe($user, new RecipeBrief(
            protein: $protein,
            fat: $fat,
            carbs: $carbs,
            kcal: $kcal,
            mealType: $mealType,
            complexity: $complexity,
            ingredients: array_map('trim', $ingredients),
            preferences: $preferences === '' ? null : $preferences,
            diets: self::diets($user),
        ));
    }

    /**
     * RCP-003
     *
     * @throws PageUnavailable when the page is refused or cannot be fetched
     */
    public function import(User $user, string $url): AiRecipe
    {
        return $this->ai->recipeImport($user, trim($url));
    }

    /** @return list<DietFilter> the profile's filters, in the filters' own order */
    private static function diets(User $user): array
    {
        // A user who has not onboarded has no profile, and so no filters.
        $on = (array) ($user->profile->diet_filters ?? []);

        return array_values(array_filter(DietFilter::cases(), fn (DietFilter $diet): bool => in_array($diet->value, $on, true)));
    }
}
