<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A waist measurement in the user's unit, tagged with it (WT-021, CAP-015). */
final class WaistInput extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 0)]
        public readonly float $value,
        #[Field(enum: ['cm', 'in'])]
        public readonly string $unit,
    ) {}
}
