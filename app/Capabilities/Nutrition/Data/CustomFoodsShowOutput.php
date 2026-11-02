<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** custom_foods_show: the Custom foods manager (FOOD-061). */
final class CustomFoodsShowOutput extends CapabilityData
{
    public function __construct(
        /** @var list<CustomFoodRow> */
        #[Field(items: CustomFoodRow::class)]
        public readonly array $foods,
        public readonly ?string $empty_text,
    ) {}
}
