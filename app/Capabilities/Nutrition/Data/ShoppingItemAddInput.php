<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** shopping_item_add: a manual item, kept in Other until deleted (SHOP-005). */
final class ShoppingItemAddInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The item\'s ULID (CAP-019)')]
        public readonly string $id,
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $text,
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
        ];
    }
}
