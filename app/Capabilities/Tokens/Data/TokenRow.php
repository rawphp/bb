<?php

namespace App\Capabilities\Tokens\Data;

use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Support\CapabilityData;

/** A personal access token, without its secret (CAP-021). */
final class TokenRow extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $scope_text,
        public readonly string $created_text,
        public readonly string $expires_text,
        public readonly string $last_used_text,
        public readonly Status $status,
    ) {}
}
