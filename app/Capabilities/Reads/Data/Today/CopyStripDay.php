<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A day of the Copy sheet's week strip. */
final class CopyStripDay extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        public readonly string $letter,
        public readonly string $number,
        public readonly string $calories_text,
        public readonly bool $is_today,
        public readonly bool $is_shown,
        public readonly bool $has_training,
        public readonly bool $is_before_plan,
        #[Field(description: 'false for a day that cannot take the copy.')] public readonly bool $enabled,
    ) {}
}
