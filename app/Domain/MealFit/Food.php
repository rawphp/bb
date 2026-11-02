<?php

namespace App\Domain\MealFit;

/** FOOD-004: a food. Nutrients are per 100 g as sold (raw). */
final readonly class Food
{
    /**
     * @param  ?float  $cookedRatio  FOOD-006: cooked grams per raw gram. Null when cooking does not change the weight.
     * @param  list<Serving>  $servings
     */
    public function __construct(
        public string $id,
        public string $name,
        public float $kcal,
        public float $proteinG,
        public float $fatG,
        public float $carbsG,
        public ?float $cookedRatio = null,
        public array $servings = [],
        public ?CuratedGroup $curatedGroup = null,
    ) {}
}
