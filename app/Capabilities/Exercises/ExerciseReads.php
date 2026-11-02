<?php

namespace App\Capabilities\Exercises;

use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Workout\WorkoutReads;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The reads an exercise write changes (CAP-011, CAP-017), and the one rule for an exercise id that arrives as text. */
final class ExerciseReads
{
    /**
     * The integer id of an exercise named in an input. Exercise ids are integers sent as strings of digits, and MySQL
     * matches "12abc" to row 12, so anything else is not found.
     */
    public static function id(string $id): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
            throw (new ModelNotFoundException)->setModel(Exercise::class, [$id]);
        }

        return (int) $id;
    }

    public static function show(int $exerciseId): string
    {
        return ReadKey::for('exercise_show', ['exercise_id' => (string) $exerciseId]);
    }

    public static function history(int $exerciseId): string
    {
        return ReadKey::for('exercise_history_show', ['exercise_id' => (string) $exerciseId]);
    }

    /** @return list<string> the workout_show of each of the user's sessions that has the exercise */
    public static function workouts(User $user, int $exerciseId): array
    {
        $keys = [];
        $blocks = WorkoutExercise::query()->where('user_id', $user->id)->where('exercise_id', $exerciseId)->with('session')->get();
        foreach ($blocks as $block) {
            if ($block->session !== null && $block->session->user_id === $user->id && $block->session->mesocycle_id !== null) {
                $keys[] = WorkoutReads::workout($block->session);
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> the Train tab and the mesocycle_show of each of the user's mesocycles with a slot for the exercise */
    public static function mesocycles(User $user, int $exerciseId): array
    {
        $ids = Mesocycle::query()->where('user_id', $user->id)->get()
            ->filter(fn (Mesocycle $mesocycle) => collect($mesocycle->dayRows())->flatMap(fn (array $day) => $day['slots'])->contains('exercise_id', $exerciseId))
            ->map(fn (Mesocycle $mesocycle) => (string) $mesocycle->id)
            ->values()->all();

        return $ids === [] ? [] : MesocycleReads::keys(...$ids);
    }
}
