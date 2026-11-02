<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** A capability a custom scope can include. */
final class TokenCapabilityRow extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
    ) {}
}
