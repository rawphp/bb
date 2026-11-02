<?php

namespace App\Capabilities\Tokens\Data;

use App\Capabilities\Support\SettingsValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** token_create: a new personal access token (CAP-021, INT-001). */
final class TokenCreateInput extends CapabilityData
{
    /**
     * @param  list<TokenCapability>  $capabilities
     */
    public function __construct(
        #[Field(description: 'the new token\'s ULID (CAP-019)')]
        public readonly string $id,
        #[Field(minLength: 1, maxLength: 100)]
        public readonly string $name,
        #[Field(enum: [30, 90, 365])]
        public readonly int $expires_in_days,
        #[Field(enum: SettingsValues::TOKEN_SCOPES)]
        public readonly string $scope,
        #[Field(description: 'the custom scope; empty for the others', items: TokenCapability::class)]
        public readonly array $capabilities,
    ) {}

    public static function rules(): array
    {
        return ['id' => ['ulid']];
    }
}
