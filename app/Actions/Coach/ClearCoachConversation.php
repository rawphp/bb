<?php

namespace App\Actions\Coach;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Rawphp\Capabilities\Support\CapabilityFailure;
use Rawphp\CapabilitiesAi\Models\Conversation;
use Rawphp\CapabilitiesAi\Models\Turn;

/**
 * AGT-020, AGT-024: deletes every conversation the user owns. The package's foreign
 * keys cascade to their messages, turns and proposals. Refused, deleting nothing,
 * while one of the user's turns is queued or running.
 */
final class ClearCoachConversation
{
    public const BUSY = 'The Coach is still answering. Try again when it has finished.';

    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Locked, so a turn stored in one of them meanwhile waits for this to finish, and then finds it gone.
            $ids = Conversation::query()->where('user_id', (string) $user->getKey())->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) {
                return;
            }

            $busy = Turn::query()
                ->whereIn('conversation_id', $ids)
                ->whereIn('status', [Turn::STATUS_QUEUED, Turn::STATUS_RUNNING])
                ->exists();
            if ($busy) {
                throw CapabilityFailure::conflict(self::BUSY);
            }

            Conversation::query()->whereKey($ids->all())->delete();
        });
    }
}
