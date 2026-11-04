<?php

namespace App\Domain\Copy;

/**
 * MEAL-061: which earlier meals an empty meal offers to have again. The same meal by name, that held foods, newest day
 * first; a day that held the same foods as a newer one is left out, whatever their order; three at most.
 */
final class MealRepeats
{
    /** How many days before the meal's day are looked at. */
    public const LOOKBACK_DAYS = 28;

    /** How many are offered. */
    public const LIMIT = 3;

    /**
     * @param  list<PastMeal>  $meals
     * @return list<PastMeal>
     */
    public function pick(string $name, array $meals): array
    {
        $same = array_values(array_filter($meals, fn (PastMeal $meal) => $meal->name === $name && $meal->entryKeys !== []));
        usort($same, fn (PastMeal $a, PastMeal $b) => $b->day <=> $a->day);
        $picked = [];
        foreach ($same as $meal) {
            $keys = $meal->entryKeys;
            sort($keys);
            $signature = implode("\n", $keys);
            if (! isset($picked[$signature])) {
                $picked[$signature] = $meal;
            }
            if (count($picked) === self::LIMIT) {
                break;
            }
        }

        return array_values($picked);
    }
}
