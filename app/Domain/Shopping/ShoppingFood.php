<?php

namespace App\Domain\Shopping;

/** A food as the shopping list needs it. */
final readonly class ShoppingFood
{
    /** @param  ?float  $cookedRatio  cooked grams per raw gram (chicken 0.75, white rice 3.0); null when raw and cooked do not differ */
    public function __construct(
        public string $id,
        public string $name,
        public StoreCategory $category,
        public ?float $cookedRatio = null,
    ) {}
}
