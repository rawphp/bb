<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A store category and its lines (SHOP-003). */
final class ShoppingSection extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['protein', 'produce', 'grains_and_bread', 'dairy', 'pantry', 'frozen', 'other'])]
        public readonly string $category,
        public readonly string $title,
        /** @var list<ShoppingRow> */
        #[Field(items: ShoppingRow::class)]
        public readonly array $rows,
    ) {}
}
