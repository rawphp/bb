<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** shopping_item_check: tick a line off, or take the tick back (SHOP-005). */
final class ShoppingItemCheckInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $from,
        #[Field(format: 'date')]
        public readonly string $to,
        public readonly string $key,
        public readonly bool $ticked,
    ) {}
}
