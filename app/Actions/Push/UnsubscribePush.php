<?php

namespace App\Actions\Push;

use App\Models\User;

/** Remove the user's subscription for this browser. Nothing to remove is fine. */
final class UnsubscribePush
{
    public function __invoke(User $user, string $endpoint): void
    {
        $user->pushSubscriptions()->where('endpoint', $endpoint)->delete();
    }
}
