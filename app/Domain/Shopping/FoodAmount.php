<?php

namespace App\Domain\Shopping;

use App\Domain\MealFit\Weighing;

/** An amount of one food, weighed raw or cooked. */
final readonly class FoodAmount
{
    public function __construct(
        public ShoppingFood $food,
        public float $grams,
        public Weighing $weighing = Weighing::Raw,
    ) {}

    /** SHOP-004: the raw weight to buy. */
    public function rawGrams(): float
    {
        return $this->weighing->rawGrams($this->grams, $this->food->cookedRatio);
    }

    public function scaled(float $scale): self
    {
        return new self($this->food, $this->grams * $scale, $this->weighing);
    }
}
