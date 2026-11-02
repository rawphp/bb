<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** shopping_show: the list for a date range (SHOP-001; default the next 7 days). */
final class ShoppingShowInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly ?string $from = null,
        #[Field(format: 'date')]
        public readonly ?string $to = null,
    ) {}

    public static function rules(): array
    {
        return ShoppingRange::rules();
    }
}
