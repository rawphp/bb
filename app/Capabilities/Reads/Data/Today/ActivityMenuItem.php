<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An entry of the Other activity card's menu (SCH-032). */
final class ActivityMenuItem extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['edit', 'delete'])] public readonly string $action,
        public readonly string $label,
    ) {}
}
