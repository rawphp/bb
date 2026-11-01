<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The step row (SCH-011). */
final class Steps extends CapabilityData
{
    public function __construct(
        #[Field(description: '"Step count target 6-8k"')] public readonly string $target_text,
        #[Field(description: '"5,432 steps"')] public readonly ?string $actual_text,
    ) {}
}
