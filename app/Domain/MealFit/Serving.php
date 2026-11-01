<?php

namespace App\Domain\MealFit;

/** FOOD-004: a household unit, "slice" is 40 g. Measured as sold (raw). */
final readonly class Serving
{
    public function __construct(
        public string $label,
        public float $grams,
    ) {}
}
