<?php

namespace App\Actions\Billing;

use App\Billing\StripeGateway;
use App\Domain\Billing\BillingRule;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * billing_checkout_start: a Stripe Checkout page for the plan (SUB-005). The
 * account gets one Stripe customer, made on its first Checkout, and the free
 * trial if Stripe has never billed it (SUB-003).
 */
final class StartCheckout
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function __invoke(User $user, string $plan): string
    {
        $price = (string) config("billing.prices.{$plan}");
        $subscription = $this->customer($user);
        $trialDays = $subscription->hasSubscribed() ? null : BillingRule::TRIAL_DAYS;

        return $this->stripe->checkoutUrl($subscription->stripe_customer_id, $price, $trialDays, (string) config('app.url'));
    }

    /** The account's row with its Stripe customer, made under a lock so two Checkouts cannot make two customers. */
    private function customer(User $user): Subscription
    {
        return DB::transaction(function () use ($user): Subscription {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            return Subscription::of($user) ?? Subscription::query()->create([
                'user_id' => $user->getKey(),
                'stripe_customer_id' => $this->stripe->createCustomer($user->email, $user->name, (int) $user->getKey()),
            ]);
        });
    }
}
