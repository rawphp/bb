<?php

namespace App\Actions\WeighIns;

use App\Models\User;
use App\Models\WeighIn;
use App\Support\UserDay;

/** WT-004: delete one of the user's weigh-ins. */
final class DeleteWeighIn
{
    /** @return string the user's local day, Y-m-d, it was on (SET-012) */
    public function __invoke(User $user, string $id): string
    {
        $weighIn = WeighIn::query()->where('user_id', $user->id)->findOrFail($id);
        $weighIn->delete();

        return UserDay::dayOf($user, $weighIn->weighed_at)->format('Y-m-d');
    }
}
