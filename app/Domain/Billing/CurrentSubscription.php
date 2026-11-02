<?php

namespace App\Domain\Billing;

/**
 * Which of a Stripe customer's subscriptions decides the account (SUB-021).
 * A customer can hold more than one: an old one that ended, or a second one
 * bought by mistake. Paid decides over trial, a live one over an ended one,
 * an ended one over one whose first payment has not gone through, and among
 * alike the newest.
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

    /**
     * Whether Stripe may charge the customer again (ACC-005, SUB-007): a trial, a paid subscription, or a first payment
     * still being taken, which becomes one once it goes through, unless it is set to end at its period's end.
     *
     * @param  list<array<string, mixed>>  $subscriptions  Stripe subscription objects of one customer
     */
    public static function anyRenews(array $subscriptions): bool
    {
        foreach ($subscriptions as $subscription) {
            $status = isset($subscription['status']) ? (string) $subscription['status'] : null;
            $charges = $status === 'incomplete' || in_array(Access::fromStripeStatus($status), [Access::Trial, Access::Active], true);
            if ($charges && ! ($subscription['cancel_at_period_end'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $subscription */
    private static function rank(array $subscription): int
    {
        return Access::fromStripeStatus(isset($subscription['status']) ? (string) $subscription['status'] : null)->rank();
    }
}
