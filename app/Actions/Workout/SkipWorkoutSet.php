<?php

namespace App\Actions\Workout;

use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\OpenWorkout;
use Illuminate\Support\Facades\DB;

/** WO-020: skips or unskips a set. A skipped set stays, greyed, and is never logged or counted as working (WO-025). */
final class SkipWorkoutSet
{
    public function __invoke(User $user, string $setId, bool $isSkipped): WorkoutSession
    {
        return DB::transaction(function () use ($user, $setId, $isSkipped) {
            $set = OpenWorkout::set($user, $setId);
            $set->update(['is_skipped' => $isSkipped, ...($isSkipped ? ['is_logged' => false] : [])]);

            return $set->session;
        });
    }
}
