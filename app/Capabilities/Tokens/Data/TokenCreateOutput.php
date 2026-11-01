<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** token_create's result: the write result (CAP-010) and the token, shown once. */
final class TokenCreateOutput extends CapabilityData
{
    /**
     * @param  list<string>  $invalidates
     */
    public function __construct(
        #[Field(description: 'read keys whose data changed (CAP-010)')]
        public readonly array $invalidates,
        public readonly ?string $toast,
        public readonly ?string $undo,
        public readonly CreatedToken $token,
    ) {}
}
