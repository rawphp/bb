<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The units the entry sheet shows and tags its writes with (CAP-015, SET-001). */
final class WeighInUnits extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['kg', 'lb'])]
        public readonly string $weight,
        #[Field(enum: ['cm', 'in'])]
        public readonly string $waist,
    ) {}
}
