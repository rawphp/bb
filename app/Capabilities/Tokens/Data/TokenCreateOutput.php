<?php

namespace App\Capabilities\Tokens\Data;

use App\Capabilities\Support\ShownOnce;
use App\Capabilities\Support\WriteOutput;
use App\Capabilities\Support\WriteResult;

/** token_create's result: the write result (CAP-010) and the token, shown once. */
final class TokenCreateOutput extends WriteOutput implements ShownOnce
{
    public function __construct(
        public readonly CreatedToken $token,
        public readonly WriteResult $result,
    ) {}

    public static function withoutSecret(array $data): array
    {
        if (is_array($data['token'] ?? null)) {
            $data['token']['plain_text'] = null;
        }

        return $data;
    }
}
