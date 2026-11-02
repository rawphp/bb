<?php

namespace App\Actions\Billing;

use App\Billing\StripeGateway;
use App\Models\Subscription;
use App\Models\User;
use Rawphp\Capabilities\Support\CapabilityFailure;

/** billing_portal_open: the Stripe customer portal, where the user switches plan or cancels (SUB-007). */
final class OpenPortal
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function __invoke(User $user): string
    {
        $subscription = Subscription::of($user);
        if ($subscription === null || ! $subscription->hasSubscribed()) {
            throw CapabilityFailure::conflict('There is no subscription to manage yet.');
        }

        return $this->stripe->portalUrl($subscription->stripe_customer_id, (string) config('app.url'));
    }
}
