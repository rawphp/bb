<?php

namespace App\Capabilities\Exercises;

use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Capabilities\Workout\WorkoutReads;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Support\UserDay;
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

    /**
     * The workout_show of each of the user's sessions that has the exercise, and of each day of a saved mesocycle whose
     * slots name it: a workout that has not started is built from those slots, the same walk as WorkoutSnapshot::locate().
     *
     * @return list<string>
     */
    public static function workouts(User $user, int $exerciseId): array
    {
        $keys = [];
        $blocks = WorkoutExercise::query()->where('user_id', $user->id)->where('exercise_id', $exerciseId)->with('session')->get();
        foreach ($blocks as $block) {
            if ($block->session !== null && $block->session->user_id === $user->id && $block->session->mesocycle_id !== null) {
                $keys[] = WorkoutReads::workout($block->session);
            }
        }

        foreach (Mesocycle::savedBy($user)->get() as $mesocycle) {
            for ($week = 1; $week <= (int) $mesocycle->weeks; $week++) {
                foreach (($mesocycle->earlierWeekRows()[$week - 1] ?? $mesocycle->dayRows()) as $index => $day) {
                    if (collect($day['slots'])->contains('exercise_id', $exerciseId)) {
                        $keys[] = ReadKey::for('workout_show', ['mesocycle_id' => (string) $mesocycle->id, 'week' => $week, 'day' => $index + 1]);
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> the Train tab and the mesocycle_show of every mesocycle of the user's, drafts too: each builder lists the whole library */
    public static function mesocycles(User $user): array
    {
        return MesocycleReads::keys(...Mesocycle::query()->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    /** @return list<string> the reads that show an exercise's name outside its own screens: today's session (SET-012: the user's local today) and the progress charts */
    public static function names(User $user): array
    {
        return [
            ...TodayReads::keys([UserDay::today($user)->format('Y-m-d')]),
            ReadKey::for('progress_show'),
            ReadKey::for('progress_show', ['scope' => 'phase']),
            ReadKey::for('progress_show', ['scope' => 'plan']),
        ];
    }
}
