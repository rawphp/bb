<?php

namespace App\Actions\Billing;

use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a subscription from a verified Stripe webhook to the customer's
 * account (SUB-021). Stripe does not promise to deliver events in order, so an
 * event made before the last one applied changes nothing. A customer this
 * instance did not make is ignored.
 */
final class ApplyStripeSubscription
{
    /**
     * @param  array<string, mixed>  $subscription  Stripe's subscription object
     * @param  int  $eventCreated  when Stripe made the event, in Unix seconds
     */
    public function __invoke(array $subscription, int $eventCreated): void
    {
        DB::transaction(function () use ($subscription, $eventCreated): void {
            $row = Subscription::query()
                ->where('stripe_customer_id', (string) ($subscription['customer'] ?? ''))
                ->lockForUpdate()
                ->first();

            if ($row === null || ($row->stripe_event_at !== null && $eventCreated < $row->stripe_event_at->getTimestamp())) {
                return;
            }

            /** @var array<string, mixed> $item */
            $item = $subscription['items']['data'][0] ?? [];
            // Since Stripe's 2025-03-31 API the period is on the item; before, on the subscription.
            $periodEnd = $item['current_period_end'] ?? $subscription['current_period_end'] ?? null;

            $row->forceFill([
                'stripe_subscription_id' => (string) $subscription['id'],
                'status' => (string) $subscription['status'],
                'plan' => self::plan($item['price']['id'] ?? null),
                'current_period_end' => self::time($periodEnd),
                'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
                'trial_ends_at' => self::time($subscription['trial_end'] ?? null),
                'stripe_event_at' => Carbon::createFromTimestamp($eventCreated),
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
