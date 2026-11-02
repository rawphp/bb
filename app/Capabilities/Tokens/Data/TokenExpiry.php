<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** An expiry a new token can have (CAP-021). */
final class TokenExpiry extends CapabilityData
{
    public function __construct(
        public readonly int $days,
        public readonly string $label,
    ) {}
}
