<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A day's step count and where it came from (WT-020). */
final class StepsRow extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $date,
        public readonly string $date_label,
        #[Field(description: '"8,412 steps".')]
        public readonly string $value_label,
        #[Field(description: '"Typed" or "Shortcut".')]
        public readonly string $source_label,
        #[Field(description: 'The count as the edit field starts.', minimum: 0)]
        public readonly int $steps,
    ) {}
}
