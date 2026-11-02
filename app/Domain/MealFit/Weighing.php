<?php

namespace App\Domain\MealFit;

/** FOOD-006: whether the grams of a food were weighed raw or cooked. */
enum Weighing: string
{
    case Raw = 'raw';
    case Cooked = 'cooked';

    /** FOOD-006: the raw weight behind `grams` weighed this way. */
    public function rawGrams(float $grams, ?float $cookedRatio): float
    {
        $ratio = $this->ratio($cookedRatio);

        return $ratio === null ? $grams : $grams / $ratio;
    }

    /** FOOD-006: the grams to show for `raw` grams weighed this way. */
    public function gramsForRaw(float $raw, ?float $cookedRatio): float
    {
        $ratio = $this->ratio($cookedRatio);

        return $ratio === null ? $raw : $raw * $ratio;
    }

    /** The ratio to apply, or null when the grams are raw grams. A ratio of 0 or less counts as none. */
    private function ratio(?float $cookedRatio): ?float
    {
        return $this === self::Cooked && $cookedRatio !== null && $cookedRatio > 0 ? $cookedRatio : null;
    }
}
