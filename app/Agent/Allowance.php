<?php

namespace App\Agent;

use Carbon\CarbonImmutable;

/**
 * How much agent use a user has left under the AGT-005 caps: turns per day and
 * Anthropic tokens per month. A null limit is no cap. The server words the
 * notice, so the chat only shows it.
 */
final readonly class Allowance
{
    /** Below this share of a cap left, the chat says how much is left. */
    public const NEAR = 0.2;

    private function __construct(
        public ?int $turnsLeft,
        public ?int $tokensLeft,
        public bool $atCap,
        public ?CarbonImmutable $resetsAt,
        public ?string $notice,
    ) {}

    /** `$dayEndsAt` is when the user's day ends (SET-012); without it, the app's day. */
    public static function check(?int $turnsPerDay, ?int $tokensPerMonth, int $turnsToday, int $tokensThisMonth, CarbonImmutable $now, ?CarbonImmutable $dayEndsAt = null): self
    {
        $turnsLeft = $turnsPerDay === null ? null : max($turnsPerDay - $turnsToday, 0);
        $tokensLeft = $tokensPerMonth === null ? null : max($tokensPerMonth - $tokensThisMonth, 0);

        // The monthly cap resets later, so it wins when both are reached.
        if ($tokensLeft === 0) {
            return self::reached($turnsLeft, $tokensLeft, $now->startOfMonth()->addMonth(), 'this month\'s');
        }
        if ($turnsLeft === 0) {
            return self::reached($turnsLeft, $tokensLeft, $dayEndsAt ?? $now->startOfDay()->addDay(), 'today\'s');
        }

        $notice = null;
        if ($turnsLeft !== null && $turnsLeft <= $turnsPerDay * self::NEAR) {
            $notice = $turnsLeft === 1 ? '1 Coach message left today.' : "{$turnsLeft} Coach messages left today.";
        } elseif ($tokensLeft !== null && $tokensLeft <= $tokensPerMonth * self::NEAR) {
            $percent = (int) floor($tokensLeft * 100 / $tokensPerMonth);
            $notice = "{$percent}% of this month's Coach allowance left.";
        }

        return new self($turnsLeft, $tokensLeft, false, null, $notice);
    }

    private static function reached(?int $turnsLeft, ?int $tokensLeft, CarbonImmutable $resetsAt, string $period): self
    {
        $notice = "You've reached {$period} Coach limit. It resets at {$resetsAt->format('H:i T \o\n j M')}. Everything else still works.";

        return new self($turnsLeft, $tokensLeft, true, $resetsAt, $notice);
    }
}
