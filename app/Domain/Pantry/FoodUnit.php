<?php

namespace App\Domain\Pantry;

/** FOOD-009: the unit chip beside a food's amounts. A Pantry row shows its nutrition per 100 g, or per 3.5 oz. */
enum FoodUnit: string
{
    case Grams = 'g';
    case Ounces = 'oz';

    public const GRAMS_PER_OUNCE = 28.349523125;

    /** The weight a row's nutrition is for. */
    public function basisGrams(): float
    {
        return match ($this) {
            self::Grams => 100.0,
            self::Ounces => 3.5 * self::GRAMS_PER_OUNCE,
        };
    }

    public function basisText(): string
    {
        return match ($this) {
            self::Grams => 'per 100 g',
            self::Ounces => 'per 3.5 oz',
        };
    }
}
