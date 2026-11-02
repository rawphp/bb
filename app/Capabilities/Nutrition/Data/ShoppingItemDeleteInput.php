<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** shopping_item_delete: delete a manual item (SHOP-005), with the range of the list shown. */
final class ShoppingItemDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        #[Field(format: 'date', description: 'The first day of the list shown, so the write refreshes it')]
        public readonly ?string $from = null,
        #[Field(format: 'date', description: 'The last day of the list shown')]
        public readonly ?string $to = null,
    ) {}

    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'required_with:to'],
            'to' => ['nullable', 'required_with:from', 'after_or_equal:from'],
        ];
    }
}
