<?php

namespace Tests\Support;

use App\Actions\Workout\StartWorkout;
use App\Models\ExerciseFeedback;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutIds;
use Carbon\CarbonImmutable;

/**
 * Sessions for the feedback and finish tests, on TrainSeed's mesocycle: Meso 2 from Monday 7 September 2026, five
 * weeks, "Upper A" (Bench Press, chest, 3 sets) on Monday and "Lower A" (Back Squat, quads, 3 sets, and calves with
 * no exercise) on Thursday. Each is started through workout_start's action, under the id workout_show gives it.
 */
final class WorkoutSeed
{
    /**
     * A session of the mesocycle's day started at `$at`, its planned sets each aiming for 10 reps at 80 kg and logged
     * at `$reps` (null leaves them to do).
     */
    public static function started(Mesocycle $mesocycle, int $week, int $day, string $at, ?int $reps = 10): WorkoutSession
    {
        app(StartWorkout::class)($mesocycle->user, WorkoutIds::workout((string) $mesocycle->id, $week, $day), CarbonImmutable::parse($at));
        $session = WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->where('week', $week)->where('day', $day)->sole();
        WorkoutSet::query()->where('workout_session_id', $session->id)->get()->each(fn (WorkoutSet $set) => $set->forceFill([
            'target_weight_kg' => 80, 'target_reps' => 10, 'actual_weight_kg' => $reps === null ? null : 80,
            'actual_reps' => $reps, 'is_logged' => $reps !== null,
        ])->save());

        return $session;
    }

    /** The id workout_show gives the session. */
    public static function id(WorkoutSession $session): string
    {
        return WorkoutIds::workout((string) $session->mesocycle_id, $session->week, $session->day);
    }

    /** The day's first exercise. */
    public static function exerciseId(Mesocycle $mesocycle, int $day): int
    {
        return (int) $mesocycle->dayRows()[$day - 1]['slots'][0]['exercise_id'];
    }

    /**
     * A full answer for the session's muscle, as stored rows.
     *
     * @param  array<string, ?string>  $answers  soreness, pump and volume
     */
    public static function answered(WorkoutSession $session, string $muscle, int $exerciseId, array $answers = []): void
    {
        MuscleFeedback::factory()->create(['workout_session_id' => $session->id, 'muscle_group' => $muscle] + $answers + [
            'soreness' => null, 'pump' => 'moderate', 'volume' => 'justRight',
        ]);
        ExerciseFeedback::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $exerciseId, 'joint_pain' => 'none']);
    }
}
