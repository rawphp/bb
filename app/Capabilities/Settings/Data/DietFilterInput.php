<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One diet filter. */
final class DietFilterInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan'])]
        public readonly string $filter,
    ) {}
}
