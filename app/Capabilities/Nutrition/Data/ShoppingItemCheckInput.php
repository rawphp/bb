<?php

namespace App\Capabilities\Nutrition\Data;

use App\Domain\Shopping\TickedRange;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
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

    public static function rules(): array
    {
        return [
            ...ShoppingRange::rules(),
            // Implicit, so an empty key is refused too.
            'key' => [new class implements ValidationRule
            {
                public bool $implicit = true;

                public function validate(string $attribute, mixed $value, Closure $fail): void
                {
                    if (! is_string($value) || ! TickedRange::isKey($value)) {
                        $fail('That is not a line of the shopping list.');
                    }
                }
            }],
        ];
    }
}
