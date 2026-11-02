<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A body weight in the user's unit, tagged with it (CAP-015, SET-001). */
final class WeightInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'A floor and a ceiling that hold in kg and in lb.', minimum: 20, maximum: 1000)]
        public readonly float $value,
        #[Field(enum: ['kg', 'lb'])]
        public readonly string $unit,
    ) {}
}
