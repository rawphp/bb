<?php

namespace App\Domain\Tokens;

/** How long a new personal access token lasts, in days (CAP-021). */
enum TokenLifetime: int
{
    case Month = 30;
    case Quarter = 90;
    case Year = 365;

    public function label(): string
    {
        return match ($this) {
            self::Month => '30 days',
            self::Quarter => '90 days',
            self::Year => '1 year',
        };
    }
}
