<?php

namespace App\Domain\MealFit;

/** FOOD-005: "about 2.6 slices". */
final readonly class Household
{
    public function __construct(
        public string $label,
        public float $count,
    ) {}
}
