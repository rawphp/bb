<?php

namespace App\Domain\Billing;

/**
 * Which of a Stripe customer's subscriptions decides the account (SUB-021).
 * A customer can hold more than one: an old one that ended, or a second one
 * bought by mistake. A live one decides over an ended one, paid over trial,
 * and among alike the newest.
 */
final class CurrentSubscription
{
    /**
     * @param  list<array<string, mixed>>  $subscriptions  Stripe subscription objects of one customer
     * @return array<string, mixed>|null
     */
    public static function pick(array $subscriptions): ?array
    {
        usort($subscriptions, fn (array $a, array $b): int => [self::rank($b), (int) ($b['created'] ?? 0)] <=> [self::rank($a), (int) ($a['created'] ?? 0)]);

        return $subscriptions[0] ?? null;
    }

    /** @param  array<string, mixed>  $subscription */
    private static function rank(array $subscription): int
    {
        return Access::fromStripeStatus(isset($subscription['status']) ? (string) $subscription['status'] : null)->rank();
    }
}
