<?php

namespace App\Domain\Shopping;

/** A recipe: its ingredients for the whole batch and how many servings the batch makes. */
final readonly class Recipe
{
    /** @param  list<FoodAmount>  $ingredients */
    public function __construct(
        public string $name,
        public float $servings,
        public array $ingredients,
    ) {}
}
