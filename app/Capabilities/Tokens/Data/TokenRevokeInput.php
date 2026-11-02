<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** token_revoke: the token to revoke (CAP-021). */
final class TokenRevokeInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'the token\'s ULID')]
        public readonly string $id,
    ) {}

    public static function rules(): array
    {
        return ['id' => ['ulid']];
    }
}
