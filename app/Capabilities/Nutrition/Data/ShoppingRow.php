<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A line of the shopping list (SHOP-003, SHOP-005). */
final class ShoppingRow extends CapabilityData
{
    public function __construct(
        #[Field(description: 'food:<id> or manual:<id>')]
        public readonly string $key,
        public readonly string $name,
        #[Field(description: '1100 g; null for a manual item')]
        public readonly ?string $amount_text,
        public readonly bool $is_ticked,
        public readonly bool $is_manual,
    ) {}
}
