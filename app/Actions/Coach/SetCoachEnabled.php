<?php

namespace App\Actions\Coach;

use App\Models\User;

/** AGT-020: the user's own Coach switch. The instance's switch is AgentStatus::enabled() (AGT-002). */
final class SetCoachEnabled
{
    public function __invoke(User $user, bool $on): void
    {
        $user->forceFill(['coach_on' => $on])->save();
    }
}
