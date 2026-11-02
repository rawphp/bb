<?php

namespace App\Domain\Shopping;

/** One food to buy, in raw grams rounded up to a purchase amount. */
final readonly class ShoppingItem
{
    public function __construct(
        public ShoppingFood $food,
        public float $grams,
    ) {}
}
