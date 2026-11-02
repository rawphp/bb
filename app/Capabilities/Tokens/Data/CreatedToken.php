<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The token just made. Its secret is shown this once (CAP-021); a replay (CAP-014) has none. */
final class CreatedToken extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        #[Field(description: 'the secret, shown once; null when the create is replayed')]
        public readonly ?string $plain_text,
        public readonly string $scope_text,
        public readonly string $expires_text,
    ) {}
}
