<?php

namespace App\Domain\Recipe;

use App\Domain\Model\MealTarget;

/** RCP-001, RCP-020: the words the Recipes screens show, ready to render (CAP-015). */
final class RecipeText
{
    public const NO_RECIPES = 'No recipes yet. Create one, or import one from a web page.';

    public const NO_MATCHING_RECIPES = 'No recipes match these filters.';

    public const NO_MATCHING_LIBRARY = 'No library recipes match these filters.';

    /** "330 kcal · P 28 · F 3 · C 47", or with units "330 kcal · P 28 g · F 3 g · C 47 g": whole numbers, halves away from zero. */
    public static function macros(MealTarget $macros, bool $units = false): string
    {
        $g = $units ? ' g' : '';
        $whole = fn (float $value): int => (int) round($value);

        return "{$whole($macros->kcal)} kcal · P {$whole($macros->proteinG)}{$g} · F {$whole($macros->fatG)}{$g} · C {$whole($macros->carbsG)}{$g}";
    }

    /** "1 serving", "4 servings", "1.5 servings". */
    public static function servings(float $servings): string
    {
        return self::number($servings).' '.($servings == 1 ? 'serving' : 'servings');
    }

    /** "Dinner" for dinner; no meal type has no title. */
    public static function mealTypeTitle(?string $mealType): ?string
    {
        return $mealType === null ? null : ucfirst($mealType);
    }

    /** FOOD-006: "600 g raw" or "450 g cooked" for a food with a cooked value, else "240 g". */
    public static function amount(float $grams, bool $hasCookedValue, bool $isCooked): string
    {
        $text = self::number($grams).' g';

        return $hasCookedValue ? $text.($isCooked ? ' cooked' : ' raw') : $text;
    }

    /** What an empty list says: the library can only be filtered empty; the user's own list can also have none yet. */
    public static function emptyText(string $segment, bool $filtered): string
    {
        return match (true) {
            $segment === 'library' => self::NO_MATCHING_LIBRARY,
            $filtered => self::NO_MATCHING_RECIPES,
            default => self::NO_RECIPES,
        };
    }

    /** A number as typed: up to two places, no trailing zeros. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
