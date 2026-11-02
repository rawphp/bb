<?php

namespace App\Support;

use App\Http\Middleware\AcceptOccurredAt;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * When the user acted (OFF-007). The app's outbox sends each write with the moment
 * it was made, which may be days before it reaches the server, so anything tied to
 * a date or time (the day a set or check-in belongs to, a workout's start and end)
 * reads it from here, not from the clock. {@see AcceptOccurredAt}
 * checks the window and keeps it for the request; anywhere else it is now.
 */
final class OccurredAt
{
    public const HEADER = 'X-BB-Occurred-At';

    public const ATTRIBUTE = 'bb_occurred_at';

    public const MAX_AGE_DAYS = 7;

    public const MAX_AHEAD_MINUTES = 5;

    /** The request's occurred_at, else the current time (walked in local and testing, SET-011). */
    public static function get(): CarbonImmutable
    {
        $at = request()->attributes->get(self::ATTRIBUTE);

        return $at instanceof CarbonImmutable ? $at : CarbonImmutable::now();
    }

    public static function parse(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** Why a write made at $at can't be saved at $now, in plain words (CAP-012), or null when it can. */
    public static function refusal(?CarbonImmutable $at, CarbonImmutable $now): ?string
    {
        return match (true) {
            $at === null => 'The time this change was made could not be read.',
            $at->lessThan($now->subDays(self::MAX_AGE_DAYS)) => 'This change was made more than '.self::MAX_AGE_DAYS.' days ago, so it can\'t be saved.',
            $at->greaterThan($now->addMinutes(self::MAX_AHEAD_MINUTES)) => 'This change is dated in the future. Check the date and time on this device.',
            default => null,
        };
    }
}
