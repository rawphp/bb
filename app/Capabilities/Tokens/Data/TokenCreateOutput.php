<?php

namespace App\Capabilities\Tokens\Data;

use App\Capabilities\Support\WriteOutput;
use App\Capabilities\Support\WriteResult;

/** token_create's result: the write result (CAP-010) and the token, shown once. */
final class TokenCreateOutput extends WriteOutput
{
    public function __construct(
        public readonly CreatedToken $token,
        public readonly WriteResult $result,
    ) {}
}
