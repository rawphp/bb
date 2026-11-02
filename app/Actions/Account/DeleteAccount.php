<?php

namespace App\Actions\Account;

use App\Billing\StripeGateway;
use App\Domain\Billing\CurrentSubscription;
use App\Models\Subscription;
use App\Models\User;
use DomainException;
use RuntimeException;

/**
 * ACC-005: deletes the account and all its data. Every user table's user_id cascades, and `User` deletes first the
 * rows a cascade cannot reach in order, so the account and everything in it go together or not at all.
 *
 * With billing on, a subscription Stripe would still charge is cancelled first, in Manage subscription (SUB-007):
 * the row linking the account to Stripe goes with the account, so nothing could cancel it afterwards. Stripe says
 * which of the customer's subscriptions renew (SUB-021); the stored row holds only the one the sync picked.
 */
final class DeleteAccount
{
    public const CANCEL_FIRST = 'Cancel your subscription in Manage subscription first, then delete your account.';

    public function __invoke(User $user): void
    {
        if (config('billing.enabled')) {
            $this->refuseWhileRenewing($user);
        }

        $user->delete();
    }

    private function refuseWhileRenewing(User $user): void
    {
        $customer = Subscription::of($user)?->stripe_customer_id;
        if ($customer === null) {
            return;
        }

        try {
            // Resolved here, with billing on: the real gateway needs the instance's Stripe secret.
            $subscriptions = app(StripeGateway::class)->subscriptions($customer);
        } catch (RuntimeException $unavailable) {
            // Nothing is deleted while Stripe cannot say: a refusal the user can retry, not an unsure answer.
            throw new DomainException($unavailable->getMessage(), previous: $unavailable);
        }

        if (CurrentSubscription::anyRenews($subscriptions)) {
            throw new DomainException(self::CANCEL_FIRST);
        }
    }
}
