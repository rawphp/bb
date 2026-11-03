<?php

namespace App\Actions\Workout;

use App\Domain\Mesocycle\DayEdits;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\MesocycleDay;
use App\Support\Workout\SessionBlocks;
use App\Support\Workout\WorkoutSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WO-031 (rp-clone addExercise): adds a library exercise last in a session under way, under the client's ULID
 * (CAP-019), with three regular sets targeted as a planned exercise is (PROG-020, WO-006). With `$remainingWeeks`
 * (MESO-045) the day gets it too, as a three-set slot, from this week on. A replay of the same ULID changes nothing.
 */
final class AddWorkoutExercise
{
    public function __invoke(User $user, string $workoutId, string $blockId, int $exerciseId, bool $remainingWeeks): WorkoutSession
    {
        // The mesocycle is found before the transaction and locked first, so the replay check below sees the latest commit.
        [$mesocycle] = WorkoutSnapshot::locate($user, $workoutId);

        return DB::transaction(function () use ($user, $workoutId, $blockId, $exerciseId, $remainingWeeks, $mesocycle) {
            SessionBlocks::lock((string) $mesocycle->id);
            $snapshot = WorkoutSnapshot::lockFor($user, $workoutId);
            $session = $snapshot->session();
            $refusal = $snapshot->status()->refusal();
            if ($session === null || $refusal !== null) {
                throw new DomainException((string) $refusal);
            }
            $id = strtolower($blockId);
            if (SessionBlocks::made($user, $id) !== null) {
                return $session;
            }
            $exercise = Exercise::query()->visibleTo($user)->findOrFail($exerciseId);

            (new WorkoutExercise([
                'workout_session_id' => $session->id,
                'exercise_id' => $exercise->id,
                'position' => count(SessionBlocks::blocks($session)) + 1,
            ]))->forceFill(['id' => $id, 'user_id' => $user->id])->save();
            foreach ($snapshot->plannedLoads($exercise, DayEdits::ADDED_SETS) as $index => $load) {
                (new WorkoutSet([
                    'workout_session_id' => $session->id,
                    'workout_exercise_id' => $id,
                    'exercise_id' => $exercise->id,
                    'position' => $index + 1,
                    'type' => 'regular',
                    'variant' => 'straight',
                    'target_weight_kg' => $load->weight === null ? null : $snapshot->kg($load->weight),
                    'target_reps' => $load->reps,
                    'is_logged' => false,
                    'is_skipped' => false,
                ]))->forceFill(['user_id' => $user->id])->save();
            }

            if ($remainingWeeks) {
                // One slot id wherever the day runs, so its sessions in every week plan the same slot.
                $slotId = (string) Str::uuid7();
                MesocycleDay::slots($snapshot, fn (array $slots) => DayEdits::appending($slots, $slotId, (int) $exercise->id, $exercise->muscle_group->value));
            }

            return $session;
        });
    }
}
