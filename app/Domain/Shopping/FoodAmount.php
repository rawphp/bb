<?php

namespace App\Domain\Shopping;

/** An amount of one food, weighed raw or cooked. */
final readonly class FoodAmount
{
    public function __construct(
        public ShoppingFood $food,
        public float $grams,
        public Weighed $weighed = Weighed::Raw,
    ) {}

    /** SHOP-004: the raw weight to buy. */
    public function rawGrams(): float
    {
        $ratio = $this->food->cookedRatio;
        if ($this->weighed !== Weighed::Cooked || $ratio === null || $ratio <= 0) {
            return $this->grams;
        }

        return $this->grams / $ratio;
    }

    public function scaled(float $scale): self
    {
        return new self($this->food, $this->grams * $scale, $this->weighed);
    }
}
