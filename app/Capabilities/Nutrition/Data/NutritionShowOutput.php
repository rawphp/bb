<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** nutrition_show: the day's meals (NAV-001). */
final class NutritionShowOutput extends CapabilityData
{
    public function __construct(
        public readonly string $date_text,
        /** @var list<NutritionMeal> */
        #[Field(items: NutritionMeal::class)]
        public readonly array $meals,
        public readonly ?string $empty_text,
    ) {}
}
