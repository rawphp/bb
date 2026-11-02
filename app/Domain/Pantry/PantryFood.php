<?php

namespace App\Domain\Pantry;

use App\Domain\MealFit\CuratedGroup;
use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;

/** A food as the Pantry lists it (FOOD-002, FOOD-006, FOOD-009). */
final readonly class PantryFood
{
    /** @param  ?string  $brand  the brand or restaurant */
    public function __construct(
        public Food $food,
        public ?string $brand = null,
        public bool $isCurated = false,
    ) {}

    public function id(): string
    {
        return $this->food->id;
    }

    public function group(): ?CuratedGroup
    {
        return $this->food->curatedGroup;
    }

    /** FOOD-009: the nutrition of the unit's basis weight, weighed raw. */
    public function raw(FoodUnit $unit): MealTarget
    {
        return (new MealFood($this->food->id, $this->food, $unit->basisGrams()))->macros();
    }

    /** FOOD-006: the same weight weighed cooked; null when cooking does not change the food's weight, so it has no Raw/Cooked toggle. */
    public function cooked(FoodUnit $unit): ?MealTarget
    {
        if ($this->food->cookedRatio === null || $this->food->cookedRatio <= 0) {
            return null;
        }

        return (new MealFood($this->food->id, $this->food, $unit->basisGrams(), Weighing::Cooked))->macros();
    }
}
