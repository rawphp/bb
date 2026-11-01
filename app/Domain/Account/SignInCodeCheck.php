<?php

namespace App\Domain\Account;

use Carbon\CarbonImmutable;

/**
 * The emailed sign-in code (ACC-001): six digits, valid ten minutes, five tries.
 * After the fifth wrong try, or once expired, the user asks for a new code.
 */
final class SignInCodeCheck
{
    public const VALID_MINUTES = 10;

    public const TRIES = 5;

    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    public static function check(
        bool $matches,
        int $failedTries,
        CarbonImmutable $issuedAt,
        CarbonImmutable $now,
    ): SignInCodeOutcome {
        if ($now->gte($issuedAt->addMinutes(self::VALID_MINUTES))) {
            return SignInCodeOutcome::Expired;
        }

        if ($failedTries >= self::TRIES) {
            return SignInCodeOutcome::UsedUp;
        }

        if ($matches) {
            return SignInCodeOutcome::Accepted;
        }

        return $failedTries + 1 >= self::TRIES ? SignInCodeOutcome::UsedUp : SignInCodeOutcome::Wrong;
    }
}
