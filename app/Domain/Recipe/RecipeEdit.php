<?php

namespace App\Domain\Recipe;

/** RCP-001, RCP-003, RCP-010: what a saved recipe keeps of what the editor or a draft sends. */
final class RecipeEdit
{
    /** RCP-001: a recipe has at most this many servings. */
    public const MAX_SERVINGS = 100.0;

    /**
     * The steps that have text, trimmed: blank lines are not saved.
     *
     * @param  list<string>  $steps
     * @return list<string>
     */
    public static function steps(array $steps): array
    {
        return array_values(array_filter(array_map('trim', $steps), fn (string $step): bool => $step !== ''));
    }

    /** FOOD-006: only a food whose weight changes when cooked has the raw or cooked choice. */
    public static function hasCookedValue(?float $cookedRatio): bool
    {
        return $cookedRatio !== null && $cookedRatio > 0;
    }

    /** FOOD-006: an amount is weighed cooked only for a food with a cooked value. */
    public static function isCooked(bool $isCooked, ?float $cookedRatio): bool
    {
        return $isCooked && self::hasCookedValue($cookedRatio);
    }

    /** RCP-003, RCP-010: a drafted recipe starts at 1 to 100 servings, the range recipe_save takes. */
    public static function draftServings(float $servings): float
    {
        return min(max($servings, 1.0), self::MAX_SERVINGS);
    }
}
