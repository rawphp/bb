<?php

namespace App\Capabilities\Tokens\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** token_create's result: the write result (CAP-010) and the token, shown once. */
final class TokenCreateOutput extends CapabilityData
{
    /**
     * @param  list<string>  $invalidates  read keys whose data changed, as WriteResult (CAP-010)
     */
    public function __construct(
        public readonly CreatedToken $token,
        public readonly array $invalidates,
        public readonly ?string $toast = null,
        public readonly ?string $undo = null,
    ) {}
}
