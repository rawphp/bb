<?php

namespace App\Actions\Billing;

use App\Billing\StripeGateway;
use App\Domain\Billing\Access;
use App\Domain\Billing\BillingRule;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Rawphp\Capabilities\Support\CapabilityFailure;

/**
 * billing_checkout_start: a Stripe Checkout page for the plan (SUB-005). The
 * account gets one Stripe customer, made on its first Checkout, and the free
 * trial if Stripe has never billed it (SUB-003). An account with a live
 * subscription switches plans in the portal instead (SUB-007), so Checkout
 * never sells it a second one.
 */
final class StartCheckout
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function __invoke(User $user, string $plan): string
    {
        // The schema lists every plan; an instance sells only those with a Stripe price (SUB-002).
        $price = config("billing.prices.{$plan}");
        if (! is_string($price) || $price === '') {
            throw CapabilityFailure::conflict('That plan is not on sale.');
        }

        $subscription = Subscription::of($user);
        if (in_array(Access::fromStripeStatus($subscription?->status), [Access::Trial, Access::Active], true)) {
            throw CapabilityFailure::conflict('You already have a subscription. Switch plans in Manage subscription.');
        }

        $subscription ??= $this->customer($user);
        $trialDays = $subscription->hasSubscribed() ? null : BillingRule::TRIAL_DAYS;

        return $this->stripe->checkoutUrl($subscription->stripe_customer_id, $price, $trialDays, (string) config('app.url'));
    }

    /**
     * Makes the account's Stripe customer, then keeps it under a lock on the
     * user, so two first Checkouts at once share one row. Stripe is called
     * before the lock, so a slow Stripe holds no database lock; the loser of
     * that race leaves an unused customer in Stripe, which bills nothing.
     */
    private function customer(User $user): Subscription
    {
        $customer = $this->stripe->createCustomer($user->email, $user->name, (int) $user->getKey());

        return DB::transaction(function () use ($user, $customer): Subscription {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            return Subscription::of($user) ?? Subscription::query()->create([
                'user_id' => $user->getKey(),
                'stripe_customer_id' => $customer,
            ]);
        });
    }
}
