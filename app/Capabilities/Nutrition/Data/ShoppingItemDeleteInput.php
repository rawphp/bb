<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** shopping_item_delete: delete a manual item (SHOP-005). */
final class ShoppingItemDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $id,
    ) {}
}
