<?php

namespace App\Domain\Account;

use Carbon\CarbonImmutable;

/**
 * The emailed sign-in code (ACC-001): six digits, valid ten minutes, five tries.
 * After the fifth wrong try, or once expired, the user asks for a new code.
 * A new code resets the five tries, so wrong codes are also capped across codes,
 * and a new code is not sent within a minute of the last one.
 */
final class SignInCodeCheck
{
    public const VALID_MINUTES = 10;

    public const TRIES = 5;

    public const RESEND_AFTER_SECONDS = 60;

    /**
     * Wrong codes allowed across codes (window in seconds => wrong codes). The tight cap is per
     * address and IP, so one stranger cannot lock the owner out; the loose one is per address
     * from anywhere, so many IPs together still get only 50 guesses a day.
     */
    public const WRONG_CODES_PER_ADDRESS_AND_IP = [3600 => 10, 86400 => 20];

    public const WRONG_CODES_PER_ADDRESS = [86400 => 50];

    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    public static function canResend(CarbonImmutable $lastIssuedAt, CarbonImmutable $now): bool
    {
        return $now->gte($lastIssuedAt->addSeconds(self::RESEND_AFTER_SECONDS));
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
