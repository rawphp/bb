<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** nutrition_show: the Nutrition tab for a day. */
final class NutritionShowInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date', description: 'Defaults to today')]
        public readonly ?string $date = null,
    ) {}
}
