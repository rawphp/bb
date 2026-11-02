<?php

namespace App\Actions\Today;

use App\Models\OffPlanEntry;
use App\Models\User;

/** DAY-001: an off-plan entry swiped away. Its id stays spent (CAP-019). */
final class DeleteOffPlan
{
    public function __invoke(User $user, string $id): OffPlanEntry
    {
        $entry = OffPlanEntry::query()->where('user_id', $user->id)->findOrFail($id);
        $entry->delete();

        return $entry;
    }
}
