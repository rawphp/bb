<?php

namespace App\Actions\Coach;

use App\Actions\Activity\ListActivity;
use App\Agent\ChangeScreens;
use App\Capabilities\Coach\Data\CoachChange;
use App\Capabilities\Support\Channel;
use App\Models\AuditRecord;
use App\Models\UndoToken;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\CapabilitiesAi\Models\Turn;

/**
 * The writes one of the user's Coach turns made, as change cards (AGT-013): the
 * user's agent-channel audit rows linked to the turn that succeeded, oldest first.
 * A turn that is not theirs is not found (CAP-020 step 2).
 */
final class ListCoachChanges
{
    /**
     * @return list<CoachChange>
     *
     * @throws ModelNotFoundException<Turn> when the turn is not one of the user's
     */
    public function __invoke(User $user, string $turn): array
    {
        $owned = Turn::query()
            ->where('ulid', $turn)
            ->whereHas('conversation', fn ($conversation) => $conversation->where('user_id', (string) $user->getKey()))
            ->exists();
        if (! $owned) {
            throw (new ModelNotFoundException)->setModel(Turn::class, [$turn]);
        }

        $records = AuditRecord::query()
            ->whereBelongsTo($user)
            ->where('turn_ulid', $turn)
            ->where('event', 'capability.invoked')
            ->where('channel', Channel::Agent->value)
            ->oldest()
            ->oldest('id')
            ->get(['id', 'capability', 'result', 'created_at'])
            ->filter(fn (AuditRecord $record): bool => ($record->result['ok'] ?? false) === true)
            ->values();

        $tokens = $records->map(fn (AuditRecord $record): mixed => $record->result['summary']['undo'] ?? null)->filter(fn (mixed $token): bool => is_string($token))->values();
        $live = $tokens->isEmpty() ? [] : UndoToken::query()
            ->whereBelongsTo($user)
            ->whereKey($tokens->all())
            ->where('expires_at', '>', now())
            ->pluck('id')
            ->flip()
            ->all();

        return $records->map(function (AuditRecord $record) use ($live): CoachChange {
            $undo = $record->result['summary']['undo'] ?? null;

            return new CoachChange(
                id: $record->id,
                title: ListActivity::title($record),
                screen: $record->capability === null ? null : ChangeScreens::for($record->capability),
                undo: is_string($undo) && isset($live[$undo]) ? $undo : null,
            );
        })->all();
    }
}
