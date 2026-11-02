<?php

namespace App\Domain\Scan;

use App\Domain\MealFit\Food;

/** FOOD-043: a food of the scanned meal: what the AI called it, its grams at 1× and the database food it is matched to. */
final readonly class ScanItem
{
    public function __construct(
        public string $name,
        /** The grams at 1×, unrounded: the AI's estimate, or the user's amount divided by the portion it was typed at */
        public float $baseGrams,
        /** Null when nothing matched; such a row is not added or saved. */
        public ?Food $food,
        /** The matched food's brand or restaurant (FOOD-080). */
        public ?string $detail = null,
    ) {}
}
