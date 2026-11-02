<?php

namespace App\Actions\Push;

use App\Models\PushSubscription;
use App\Models\User;

/**
 * Save this browser's subscription for the user. An endpoint is one browser,
 * so subscribing it again updates its keys, and a browser that switches
 * accounts moves to the new account.
 */
final class SubscribePush
{
    public function __invoke(User $user, string $endpoint, string $publicKey, string $authToken): PushSubscription
    {
        return PushSubscription::query()->updateOrCreate(
            ['endpoint' => $endpoint],
            ['user_id' => $user->id, 'public_key' => $publicKey, 'auth_token' => $authToken],
        );
    }
}
