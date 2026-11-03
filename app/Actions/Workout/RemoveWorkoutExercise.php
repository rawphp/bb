<?php

namespace App\Actions\Workout;

use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\SessionBlocks;
use Illuminate\Support\Facades\DB;

/** WO-022 (rp-clone removeExercise): removes an exercise and its sets from a session under way; the others close up. */
final class RemoveWorkoutExercise
{
    public function __invoke(User $user, string $blockId): WorkoutSession
    {
        return DB::transaction(function () use ($user, $blockId) {
            $block = SessionBlocks::block($user, $blockId);
            $session = $block->session;
            foreach (SessionBlocks::sets($block) as $set) {
                $set->delete();
            }
            $block->delete();
            SessionBlocks::renumber(SessionBlocks::blocks($session));

            return $session;
        });
    }
}
