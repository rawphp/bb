<?php

namespace App\Actions\Account;

use App\Models\Subscription;
use App\Models\User;
use DomainException;

/**
 * ACC-005: deletes the account and all its data. Every user table's user_id cascades, and `User` deletes first the
 * rows a cascade cannot reach in order, so the account and everything in it go together or not at all.
 *
 * With billing on, a subscription Stripe would still charge is cancelled first, in Manage subscription (SUB-007):
 * the row linking the account to Stripe goes with the account, so nothing could cancel it afterwards.
 */
final class DeleteAccount
{
    public const CANCEL_FIRST = 'Cancel your subscription in Manage subscription first, then delete your account.';

    public function __invoke(User $user): void
    {
        if (config('billing.enabled') && (Subscription::of($user)?->renews() ?? false)) {
            throw new DomainException(self::CANCEL_FIRST);
        }

        $user->delete();
    }
}
