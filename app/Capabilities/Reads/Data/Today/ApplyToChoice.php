<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An "Apply to" choice of Edit schedule (SCH-037). */
final class ApplyToChoice extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['this_day', 'all_future_days'])] public readonly string $value,
        public readonly string $label,
    ) {}
}
