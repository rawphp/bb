<?php

namespace App\Support\Workout;

use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The rows the session actions change (WO-022, WO-031, EX-008), in a session under way. The mesocycle row is locked
 * first, as every workout write that reads the whole session does ({@see WorkoutSnapshot::lockFor()}), so one structure
 * change runs at a time per mesocycle; then the exercise, through {@see OpenWorkout}, which refuses a session not under
 * way in plain words. Call it inside the write's transaction.
 */
final class SessionBlocks
{
    /** @throws ModelNotFoundException<WorkoutExercise> */
    public static function block(User $user, string $id): WorkoutExercise
    {
        $found = WorkoutExercise::query()->where('user_id', $user->id)->whereKey($id)->firstOrFail();
        $mesocycleId = $found->session?->mesocycle_id;
        if ($mesocycleId !== null) {
            Mesocycle::query()->whereKey($mesocycleId)->lockForUpdate()->first();
        }

        return OpenWorkout::block($user, $id);
    }

    /** @return list<WorkoutExercise> the session's exercises in order, locked */
    public static function blocks(WorkoutSession $session): array
    {
        return WorkoutExercise::query()->where('workout_session_id', $session->id)->orderBy('position')->lockForUpdate()->get()->all();
    }

    /** @param  list<WorkoutExercise>  $blocks  positions 1, 2, … in this order */
    public static function renumber(array $blocks): void
    {
        foreach ($blocks as $index => $block) {
            if ($block->position !== $index + 1) {
                $block->update(['position' => $index + 1]);
            }
        }
    }

    /** The session read whole, with locks: what a change written to its mesocycle's day needs. */
    public static function snapshot(User $user, WorkoutSession $session): WorkoutSnapshot
    {
        return WorkoutSnapshot::lockFor($user, WorkoutIds::workout((string) $session->mesocycle_id, $session->week, $session->day));
    }
}
