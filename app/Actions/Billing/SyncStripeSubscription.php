<?php

namespace App\Actions\Billing;

use App\Billing\StripeGateway;
use App\Domain\Billing\CurrentSubscription;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps what Stripe says about the customer now (SUB-021): after a verified
 * subscription event, and before Checkout opens. An event is only the signal: Stripe does not
 * deliver events in order, and a customer can hold more than one subscription,
 * so the state is read back from Stripe and {@see CurrentSubscription} picks
 * the one that decides. The row stays locked while Stripe is read, so two
 * events for one customer apply one after the other, the later read last.
 */
final class SyncStripeSubscription
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function __invoke(string $customer): void
    {
        DB::transaction(function () use ($customer): void {
            // A customer this instance did not make is not ours to track.
            $row = Subscription::query()->where('stripe_customer_id', $customer)->lockForUpdate()->first();
            $current = $row === null ? null : CurrentSubscription::pick($this->stripe->subscriptions($customer));

            if ($row === null || $current === null) {
                return;
            }

            /** @var array<string, mixed> $item */
            $item = $current['items']['data'][0] ?? [];
            // Since Stripe's 2025-03-31 API the period is on the item; before, on the subscription.
            $periodEnd = $item['current_period_end'] ?? $current['current_period_end'] ?? null;

            $row->forceFill([
                'stripe_subscription_id' => (string) $current['id'],
                'status' => (string) $current['status'],
                'plan' => self::plan($item['price']['id'] ?? null),
                'current_period_end' => self::time($periodEnd),
                'cancel_at_period_end' => (bool) ($current['cancel_at_period_end'] ?? false),
                'trial_ends_at' => self::time($current['trial_end'] ?? null),
            ])->save();
        });
    }

    /** The plan whose configured Stripe price this is; null for a price the instance does not sell. */
    private static function plan(mixed $price): ?string
    {
        $plan = $price === null ? false : array_search($price, (array) config('billing.prices'), true);

        return $plan === false ? null : (string) $plan;
    }

    private static function time(mixed $timestamp): ?Carbon
    {
        return is_int($timestamp) ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
