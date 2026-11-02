<?php

namespace App\Domain\Shopping;

/** The items in one store category. */
final readonly class ShoppingGroup
{
    /** @param  list<ShoppingItem>  $items */
    public function __construct(
        public StoreCategory $category,
        public array $items,
    ) {}
}
