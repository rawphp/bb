<?php

namespace App\Actions\WeighIns;

use App\Models\User;
use App\Models\WeighIn;

/** WT-004: delete one of the user's weigh-ins. */
final class DeleteWeighIn
{
    /** @return string the day, Y-m-d, it was on */
    public function __invoke(User $user, string $id): string
    {
        $weighIn = WeighIn::query()->where('user_id', $user->id)->findOrFail($id);
        $weighIn->delete();

        return $weighIn->weighed_at->format('Y-m-d');
    }
}
