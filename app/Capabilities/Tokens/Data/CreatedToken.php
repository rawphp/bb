<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** The token just made. Its secret is shown this once (CAP-021). */
final class CreatedToken extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $plain_text,
        public readonly string $scope_text,
        public readonly string $expires_text,
    ) {}
}
