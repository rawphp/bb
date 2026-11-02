<?php

namespace Tests\Support;

use App\Models\ExerciseFeedback;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Str;

/**
 * Sessions for the feedback and finish tests, on TrainSeed's mesocycle: Meso 2 from Monday 7 September 2026, five
 * weeks, "Upper A" (Bench Press, chest, 3 sets) on Monday and "Lower A" (Back Squat, quads, 3 sets, and calves with
 * no exercise) on Thursday. Sessions take a ULID, as workout_show names them.
 */
final class WorkoutSeed
{
    /**
     * A started session of the mesocycle's day holding the day's sets, each aiming for 10 reps and logged at `$reps`
     * (null leaves it to do).
     */
    public static function started(Mesocycle $mesocycle, int $week, int $day, string $at, ?int $reps = 10, ?int $sets = null): WorkoutSession
    {
        $session = WorkoutSession::factory()->for($mesocycle->user)->create([
            'id' => (string) Str::ulid(), 'mesocycle_id' => $mesocycle->id, 'week' => $week, 'day' => $day,
            'planned_start_at' => $at, 'actual_start_at' => $at,
        ]);
        $position = 1;
        foreach ($mesocycle->dayRows()[$day - 1]['slots'] as $slot) {
            if ($slot['exercise_id'] === null) {
                continue;
            }
            for ($i = 0; $i < ($sets ?? $slot['sets']); $i++) {
                WorkoutSet::factory()->create([
                    'workout_session_id' => $session->id, 'exercise_id' => $slot['exercise_id'], 'position' => $position++,
                    'target_weight_kg' => 80, 'target_reps' => 10, 'actual_weight_kg' => $reps === null ? null : 80,
                    'actual_reps' => $reps, 'is_logged' => $reps !== null,
                ]);
            }
        }

        return $session;
    }

    /** The day's first exercise. */
    public static function exerciseId(Mesocycle $mesocycle, int $day): int
    {
        return (int) $mesocycle->dayRows()[$day - 1]['slots'][0]['exercise_id'];
    }

    /** A full answer for the session's muscle, as stored rows. */
    public static function answered(WorkoutSession $session, string $muscle, int $exerciseId, array $answers = []): void
    {
        MuscleFeedback::factory()->create(['workout_session_id' => $session->id, 'muscle_group' => $muscle] + $answers + [
            'soreness' => null, 'pump' => 'moderate', 'volume' => 'justRight',
        ]);
        ExerciseFeedback::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $exerciseId, 'joint_pain' => 'none']);
    }
}
