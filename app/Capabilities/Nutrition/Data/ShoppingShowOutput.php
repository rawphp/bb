<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** shopping_show: the shopping list (SHOP-001 to SHOP-005). */
final class ShoppingShowOutput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $from,
        #[Field(format: 'date')]
        public readonly string $to,
        public readonly string $range_text,
        /** @var list<ShoppingSection> */
        #[Field(items: ShoppingSection::class)]
        public readonly array $sections,
        public readonly string $share_text,
        public readonly ?string $empty_text,
    ) {}
}
