<?php

namespace App\Support\Workout;

use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The rows the set and note writes change, each the user's own and locked for the write, in a session under way. A
 * session not started, finished or skipped refuses the change in plain words (WO-010, rp-clone isReadOnly).
 */
final class OpenWorkout
{
    /** @throws ModelNotFoundException<WorkoutSet> */
    public static function set(User $user, string $id): WorkoutSet
    {
        $set = WorkoutSet::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        self::check($set->session);

        return $set;
    }

    /** @throws ModelNotFoundException<WorkoutExercise> */
    public static function block(User $user, string $id): WorkoutExercise
    {
        $block = WorkoutExercise::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        self::check($block->session);

        return $block;
    }

    /** Positions 1, 2, … in the order the block's sets come. */
    public static function renumber(WorkoutExercise $block): void
    {
        foreach ($block->sets()->get()->values() as $index => $set) {
            if ($set->position !== $index + 1) {
                $set->update(['position' => $index + 1]);
            }
        }
    }

    private static function check(?WorkoutSession $session): void
    {
        if ($session === null) {
            throw (new ModelNotFoundException)->setModel(WorkoutSession::class);
        }
        $refusal = $session->status()->refusal();
        if ($refusal !== null) {
            throw new DomainException($refusal);
        }
    }
}
