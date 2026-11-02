<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Set a day's step count by hand (WT-020). One count per day: logging a day
 * again replaces it, and the bridge never overwrites a typed count.
 */
final class StepsLogInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $date,
        #[Field(minimum: 0, maximum: 100000)]
        public readonly int $steps,
    ) {}
}
