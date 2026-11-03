<?php

namespace App\Actions\Exercises;

use App\Models\ExerciseNote;
use App\Models\User;

/** EX-010: the user's pinned note on an exercise, trimmed. A null or blank note clears it. */
final class SetExerciseNote
{
    public function __invoke(User $user, int $exerciseId, ?string $note): void
    {
        $trimmed = trim((string) $note);
        $row = ['user_id' => $user->id, 'exercise_id' => $exerciseId];

        if ($trimmed === '') {
            ExerciseNote::query()->where($row)->delete();

            return;
        }

        ExerciseNote::query()->updateOrCreate($row, ['note' => $trimmed]);
    }
}
