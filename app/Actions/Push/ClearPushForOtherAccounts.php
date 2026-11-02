<?php

namespace App\Actions\Push;

use App\Models\PushSubscription;
use App\Models\User;

/**
 * Someone signed in on this browser: another account's subscription for it
 * stops, so that account's notifications no longer show here. The new user's
 * own row, if any, stays; they turn push on here themselves (NOTIF-010).
 */
final class ClearPushForOtherAccounts
{
    public function __invoke(User $user, string $endpoint): void
    {
        PushSubscription::query()
            ->where('endpoint', $endpoint)
            ->where('user_id', '!=', $user->id)
            ->delete();
    }
}
