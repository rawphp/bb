<?php

namespace App\Domain\SavedMeals;

/** MEAL-070, MEAL-071: the rules of a saved meal's name and the list's words. */
final class SavedMeals
{
    /** A saved meal holds at most this many foods. */
    public const MAX_FOODS = 50;

    /** MEAL-071: "3 foods", "1 food". */
    public static function detail(int $foodCount): string
    {
        return $foodCount === 1 ? '1 food' : "{$foodCount} foods";
    }

    /** MEAL-070, MEAL-071: the name trimmed; null when nothing is left. */
    public static function name(string $name): ?string
    {
        $name = trim($name);

        return $name === '' ? null : $name;
    }
}
