<?php

namespace App\Actions\Account;

use App\Actions\Billing\StartCheckout;
use App\Billing\StripeGateway;
use App\Domain\Billing\CurrentSubscription;
use App\Models\Subscription;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * ACC-005: deletes the account and all its data. Every user table's user_id cascades, and `User` deletes first the
 * rows a cascade cannot reach in order, so the account and everything in it go together or not at all.
 *
 * With billing on, a subscription Stripe would still charge is cancelled first, in Manage subscription (SUB-007):
 * the row linking the account to Stripe goes with the account, so nothing could cancel it afterwards. Stripe says
 * which of the customer's subscriptions renew (SUB-021); the stored row holds only the one the sync picked. An open
 * Checkout page is closed first, so it cannot sell one after the account is gone, and the whole delete holds the
 * Checkout lock, so no page opens between the check and the delete. Deleting never cancels in Stripe.
 */
final class DeleteAccount
{
    public const CANCEL_FIRST = 'Cancel your subscription in Manage subscription first, then delete your account.';

    public const CHECKOUT_OPENING = 'Your Checkout is still opening. Try again in a moment.';

    /** As long as StartCheckout holds it: every Stripe call it makes, at their timeouts. */
    private const LOCK_SECONDS = 120;

    public function __invoke(User $user): void
    {
        if (! config('billing.enabled')) {
            $user->delete();

            return;
        }

        $deleted = Cache::lock(StartCheckout::LOCK.$user->getKey(), self::LOCK_SECONDS)->get(function () use ($user): bool {
            $this->refuseWhileRenewing($user);
            $user->delete();

            return true;
        });
        if ($deleted !== true) {
            throw new DomainException(self::CHECKOUT_OPENING);
        }
    }

    private function refuseWhileRenewing(User $user): void
    {
        $row = Subscription::of($user);
        if ($row === null) {
            return;
        }

        try {
            // Resolved here, with billing on: the real gateway needs the instance's Stripe secret.
            $stripe = app(StripeGateway::class);
            // A page already paid shows in the list below; one still open can no longer be paid.
            if ($row->checkout_session_id !== null) {
                $stripe->closeCheckout($row->checkout_session_id);
            }
            $subscriptions = $stripe->subscriptions($row->stripe_customer_id);
        } catch (RuntimeException $unavailable) {
            // Nothing is deleted while Stripe cannot say: a refusal the user can retry, not an unsure answer.
            throw new DomainException($unavailable->getMessage(), previous: $unavailable);
        }

        if (CurrentSubscription::anyRenews($subscriptions)) {
            throw new DomainException(self::CANCEL_FIRST);
        }
    }
}
