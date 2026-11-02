<?php

namespace Tests\Support;

use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;

/**
 * Rows for the Train capabilities' seam tests: a plan whose weeks start on Monday from 2026-08-31, a user who
 * trains Monday and Thursday, and a mesocycle of two days, "Upper A" on Monday and "Lower A" on Thursday.
 */
final class TrainSeed
{
    public static function user(): User
    {
        $user = User::factory()->create();
        Plan::factory()->for($user)->create(['start_date' => '2026-08-31', 'review_weekday' => 1]);
        Profile::factory()->for($user)->create([
            'experience' => 'intermediate',
            'training_days' => [
                ['weekday' => 1, 'start' => '17:30', 'minutes' => 60],
                ['weekday' => 4, 'start' => '17:30', 'minutes' => 60],
            ],
        ]);

        return $user;
    }

    public static function phase(User $user, string $type, string $start, ?string $end = null): Phase
    {
        return Phase::factory()->for($user)->create(['type' => $type, 'start_date' => $start, 'end_date' => $end]);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function mesocycle(User $user, array $attributes = []): Mesocycle
    {
        $bench = self::exercise('Bench Press', MuscleGroup::Chest);
        $squat = self::exercise('Back Squat', MuscleGroup::Quads);

        return Mesocycle::factory()->for($user)->create(array_replace([
            'name' => 'Meso 2',
            'weeks' => 5,
            'start_date' => '2026-09-07',
            'days' => [
                ['id' => '01JB6Q400A2B3C4D5E6F7G8H9J', 'label' => 'Upper A', 'weekday' => 1, 'slots' => [
                    ['id' => '01JB6Q401A2B3C4D5E6F7G8H9J', 'exercise_id' => $bench->id, 'muscle_group' => 'chest', 'sets' => 3],
                ]],
                ['id' => '01JB6Q410A2B3C4D5E6F7G8H9J', 'label' => 'Lower A', 'weekday' => 4, 'slots' => [
                    ['id' => '01JB6Q411A2B3C4D5E6F7G8H9J', 'exercise_id' => $squat->id, 'muscle_group' => 'quads', 'sets' => 3],
                    ['id' => '01JB6Q412A2B3C4D5E6F7G8H9J', 'exercise_id' => null, 'muscle_group' => 'calves', 'sets' => 2],
                ]],
            ],
            'muscle_priorities' => ['chest' => 'emphasize', 'quads' => 'grow', 'forearms' => null],
        ], $attributes));
    }

    public static function exercise(string $name, MuscleGroup $muscle): Exercise
    {
        return Exercise::query()->where('name', $name)->first()
            ?? Exercise::factory()->create(['name' => $name, 'muscle_group' => $muscle]);
    }

    /** A finished session with its logged sets of the day's first exercise and a flat feedback sheet (PROG-054). */
    public static function trained(Mesocycle $mesocycle, int $week, int $day, string $at, bool $flat = false): WorkoutSession
    {
        $session = WorkoutSession::factory()->for($mesocycle->user)->create([
            'mesocycle_id' => $mesocycle->id, 'week' => $week, 'day' => $day,
            'actual_start_at' => "{$at} 17:30:00", 'actual_end_at' => "{$at} 18:30:00",
        ]);
        $slot = $mesocycle->dayRows()[$day - 1]['slots'][0];
        $block = WorkoutExercise::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $slot['exercise_id']]);
        foreach ([1, 2] as $position) {
            WorkoutSet::factory()->create([
                'workout_session_id' => $session->id, 'workout_exercise_id' => $block->id, 'exercise_id' => $slot['exercise_id'], 'position' => $position,
                'actual_weight_kg' => 80 + $position, 'actual_reps' => 10, 'is_logged' => true,
            ]);
        }
        MuscleFeedback::factory()->create([
            'workout_session_id' => $session->id,
            'muscle_group' => $slot['muscle_group'],
            'pump' => $flat ? 'low' : 'amazing',
            'soreness' => $flat ? 'neverGotSore' : 'healedJustOnTime',
        ]);

        return $session;
    }
}
