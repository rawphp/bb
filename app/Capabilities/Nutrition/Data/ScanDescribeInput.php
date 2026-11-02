<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** scan_describe: a typed description of the meal (FOOD-044). */
final class ScanDescribeInput extends CapabilityData
{
    public function __construct(
        #[Field(minLength: 1, maxLength: 1000)]
        public readonly string $description,
    ) {}
}
