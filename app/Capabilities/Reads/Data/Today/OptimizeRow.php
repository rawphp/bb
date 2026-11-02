<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A meal of the Optimize preview (SCH-036). */
final class OptimizeRow extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $name,
        #[Field(description: 'null for a meal the result adds (an intra-workout shake).')] public readonly ?string $before_text,
        #[Field(description: 'null for a meal the result drops.')] public readonly ?string $after_text,
        public readonly bool $changed,
    ) {}
}
