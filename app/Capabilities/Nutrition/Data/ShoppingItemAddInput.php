<?php

namespace App\Capabilities\Nutrition\Data;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** shopping_item_add: a manual item, kept in Other until deleted (SHOP-005), with the range of the list shown. */
final class ShoppingItemAddInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The item\'s ULID (CAP-019)')]
        public readonly string $id,
        #[Field(minLength: 1, maxLength: 255)]
        public readonly string $text,
        #[Field(format: 'date', description: 'The first day of the list shown, so the write refreshes it')]
        public readonly ?string $from = null,
        #[Field(format: 'date', description: 'The last day of the list shown')]
        public readonly ?string $to = null,
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
            'from' => ['nullable', 'required_with:to'],
            'to' => ['nullable', 'required_with:from', 'after_or_equal:from'],
            // Implicit: Laravel skips a plain rule for a value that is only spaces.
            'text' => [new class implements ValidationRule
            {
                public bool $implicit = true;

                public function validate(string $attribute, mixed $value, Closure $fail): void
                {
                    if (is_string($value) && trim($value) === '') {
                        $fail('Enter the item.');
                    }
                }
            }],
        ];
    }
}
