<?php

namespace App\Actions\Workout;

use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\OpenWorkout;
use Illuminate\Support\Facades\DB;

/** WO-022: this session's note on an exercise, trimmed. An empty or null note clears it. */
final class SetWorkoutExerciseNote
{
    public function __invoke(User $user, string $blockId, ?string $note): WorkoutSession
    {
        return DB::transaction(function () use ($user, $blockId, $note) {
            $block = OpenWorkout::block($user, $blockId);
            $trimmed = trim((string) $note);
            $block->update(['note' => $trimmed === '' ? null : $trimmed]);

            return $block->session;
        });
    }
}
