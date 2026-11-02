<?php

namespace App\Actions\Billing;

use App\Billing\StripeGateway;
use App\Domain\Billing\Access;
use App\Domain\Billing\BillingRule;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Rawphp\Capabilities\Support\CapabilityFailure;

/**
 * billing_checkout_start: a Stripe Checkout page for the plan (SUB-005). The
 * account gets one Stripe customer, made on its first Checkout, and the free
 * trial if Stripe has never billed it (SUB-003). An account with a live
 * subscription switches plans in the portal instead (SUB-007), so Checkout
 * never sells it a second one: Stripe is read back first, and the page opened
 * before is closed, so only one can be paid.
 */
final class StartCheckout
{
    public const SUBSCRIBED = 'You already have a subscription. Switch plans in Manage subscription.';

    public function __construct(private readonly StripeGateway $stripe) {}

    public function __invoke(User $user, string $plan): string
    {
        // The schema lists every plan; an instance sells only those with a Stripe price (SUB-002).
        $price = config("billing.prices.{$plan}");
        if (! is_string($price) || $price === '') {
            throw CapabilityFailure::conflict('That plan is not on sale.');
        }

        // One Checkout at a time per account, so two taps cannot leave two pages open.
        return Cache::lock("billing-checkout:{$user->getKey()}", 30)->block(10, fn (): string => $this->open($user, $price));
    }

    private function open(User $user, string $price): string
    {
        $subscription = Subscription::of($user);
        if ($subscription !== null) {
            // The row learns of a subscription from Stripe, after its webhook, which can lag
            // the payment; read Stripe back first, so a fresh subscription counts at once.
            (new SyncStripeSubscription($this->stripe))($subscription->stripe_customer_id);
            $subscription->refresh();

            if ($subscription->checkout_session_id !== null && $this->stripe->closeCheckout($subscription->checkout_session_id)) {
                throw CapabilityFailure::conflict(self::SUBSCRIBED);
            }
        }

        if (in_array(Access::fromStripeStatus($subscription?->status), [Access::Trial, Access::Active], true)) {
            throw CapabilityFailure::conflict(self::SUBSCRIBED);
        }

        $subscription ??= $this->customer($user);
        $trialDays = $subscription->hasSubscribed() ? null : BillingRule::TRIAL_DAYS;
        $checkout = $this->stripe->checkout($subscription->stripe_customer_id, $price, $trialDays, (string) config('app.url'));
        $subscription->forceFill(['checkout_session_id' => $checkout['id']])->save();

        return $checkout['url'];
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
