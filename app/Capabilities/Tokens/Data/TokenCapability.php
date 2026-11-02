<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A capability name for a custom scope. */
final class TokenCapability extends CapabilityData
{
    public function __construct(
        #[Field(minLength: 1, maxLength: 64)]
        public readonly string $name,
    ) {}
}
