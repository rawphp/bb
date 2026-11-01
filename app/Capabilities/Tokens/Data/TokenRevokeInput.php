<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** token_revoke: the token to revoke (CAP-021). */
final class TokenRevokeInput extends CapabilityData
{
    public function __construct(
        public readonly string $id,
    ) {}
}
