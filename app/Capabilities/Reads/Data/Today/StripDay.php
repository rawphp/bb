<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A day of the week strip (SCH-020 to SCH-022). */
final class StripDay extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(description: '"M"')] public readonly string $letter,
        #[Field(description: '"23"')] public readonly string $number,
        #[Field(description: '"2600", or "–" before the plan (SCH-021).')] public readonly string $calories_text,
        public readonly bool $is_today,
        public readonly bool $is_selected,
        #[Field(description: 'SCH-021: the green dot.')] public readonly bool $is_complete,
        #[Field(description: 'SCH-022: the dumbbell.')] public readonly bool $has_training,
        public readonly bool $is_before_plan,
    ) {}
}
