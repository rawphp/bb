<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A scope a new token can have (CAP-021, CAP-023). */
final class TokenScope extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['everything', 'read_only', 'health_bridge', 'custom'])]
        public readonly string $value,
        public readonly string $label,
        public readonly string $description,
    ) {}
}
