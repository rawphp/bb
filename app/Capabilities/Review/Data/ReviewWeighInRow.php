<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A scheduled or logged day of the reviewed week (REV-003 step 1). */
final class ReviewWeighInRow extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')]
        public readonly string $date,
        public readonly string $date_label,
        #[Field(description: '"82.5 kg"; empty when the weigh-in is missing.')]
        public readonly string $value_label,
        #[Field(description: 'A missed scheduled weigh-in: shown in orange with the section\'s `missing` pill.')]
        public readonly bool $is_missing,
        #[Field(description: 'When a late weigh-in for this day starts in the entry sheet.', format: 'date-time')]
        public readonly string $add_at,
    ) {}
}
