<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A calories choice for an away week (SCH-060). */
final class AwayCaloriesOption extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['rest', 'maintenance'])] public readonly string $value,
        public readonly string $label,
        public readonly string $text,
    ) {}
}
