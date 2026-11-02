<?php

namespace App\Domain\Account;

/** Accounts store emails trimmed and in lower case, so lookups and per-address limits use the same form. */
final class EmailAddress
{
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
