<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A training choice for an away week (SCH-060). */
final class AwayTrainingOption extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['pause', 'deload'])] public readonly string $value,
        public readonly string $label,
        public readonly string $text,
        public readonly bool $enabled,
    ) {}
}
