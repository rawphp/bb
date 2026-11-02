<?php

namespace App\Listeners;

use App\Actions\Push\ClearPushForOtherAccounts;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Signing in hands this browser to the new account, so the previous one's
 * notifications stop showing here (NOTIF-010). A session that only expired
 * leaves the browser with the account that turned push on, until someone signs
 * in or out on it.
 */
final class ClearPreviousAccountPush
{
    public function __construct(private readonly ClearPushForOtherAccounts $clear) {}

    public function handle(Login $event): void
    {
        $endpoint = PushSubscription::browserEndpoint(request());

        if ($endpoint !== null && $event->user instanceof User) {
            ($this->clear)($event->user, $endpoint);
        }
    }
}
