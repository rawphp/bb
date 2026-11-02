<?php

namespace App\Actions\Workout;

use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\OpenWorkout;
use Illuminate\Support\Facades\DB;

/** WO-020: deletes a set and numbers the exercise's other sets again. Removed sets drop from future sessions (PROG-019). */
final class DeleteWorkoutSet
{
    public function __invoke(User $user, string $setId): WorkoutSession
    {
        return DB::transaction(function () use ($user, $setId) {
            $set = OpenWorkout::set($user, $setId);
            $session = $set->session;
            $block = $set->workoutExercise;
            $set->delete();
            if ($block !== null) {
                OpenWorkout::renumber($block);
            }

            return $session;
        });
    }
}
