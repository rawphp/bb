<?php

namespace App\Domain\Scan;

use App\Domain\MealFit\Food;

/** FOOD-043: a food of the scanned meal: what the AI called it, its grams and the database food it is matched to. */
final readonly class ScanItem
{
    public function __construct(
        public string $name,
        public float $grams,
        /** Null when nothing matched; such a row is not added or saved. */
        public ?Food $food,
        /** The matched food's brand or restaurant (FOOD-080). */
        public ?string $detail = null,
    ) {}

    public function withGrams(float $grams): self
    {
        return new self($this->name, $grams, $this->food, $this->detail);
    }
}
