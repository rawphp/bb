<?php

namespace App\Actions\Workout;

use App\Domain\Progression\WorkoutSetType;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\OpenWorkout;
use Illuminate\Support\Facades\DB;

/** WO-020, WO-021: a set's type: Regular (Straight, Down or Ascending), Myorep or Myorep match. */
final class SetWorkoutSetType
{
    public function __invoke(User $user, string $setId, WorkoutSetType $type): WorkoutSession
    {
        return DB::transaction(function () use ($user, $setId, $type) {
            $set = OpenWorkout::set($user, $setId);
            $set->update(['type' => $type->type(), 'variant' => $type->variant()]);

            return $set->session;
        });
    }
}
