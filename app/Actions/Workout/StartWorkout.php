<?php

namespace App\Actions\Workout;

use App\Domain\Progression\WorkoutStatus;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutSnapshot;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * WO-010, OFF-021: starts a session by the workout id workout_show sent. It records when the user began and writes
 * the session's exercises and planned sets, with their frozen targets, under the ids the screen showed, so writes
 * queued offline after the start find them. A row that only moved the session is started in place (SCH-051). A
 * session already under way is left as it is, so a replayed start does nothing; a finished or skipped one is refused.
 */
final class StartWorkout
{
    public function __invoke(User $user, string $workoutId, DateTimeInterface $actedAt): WorkoutSession
    {
        $snapshot = WorkoutSnapshot::forWorkout($user, $workoutId);

        return DB::transaction(function () use ($user, $snapshot, $actedAt) {
            // One start at a time per mesocycle, so two starts of the same session cannot both write its sets.
            Mesocycle::query()->whereKey($snapshot->mesocycle->id)->lockForUpdate()->first();
            $started = WorkoutSession::query()->where('user_id', $user->id)->where('mesocycle_id', $snapshot->mesocycle->id)
                ->where('week', $snapshot->week)->where('day', $snapshot->day)
                ->where(fn ($query) => $query->whereNotNull('actual_start_at')->orWhere('is_skipped', true))
                ->first();
            $status = $started?->status() ?? WorkoutStatus::NotStarted;
            if ($status === WorkoutStatus::InProgress) {
                return $started;
            }
            if (! $status->isEditable()) {
                throw new DomainException((string) $status->refusal());
            }

            $session = $snapshot->session() ?? (new WorkoutSession([
                'mesocycle_id' => $snapshot->mesocycle->id,
                'week' => $snapshot->week,
                'day' => $snapshot->day,
                'planned_start_at' => $snapshot->plannedStart(),
                'planned_end_at' => $snapshot->plannedEnd(),
                'is_skipped' => false,
            ]))->forceFill(['user_id' => $user->id]);
            $session->fill(['actual_start_at' => $actedAt])->save();

            foreach ($snapshot->plannedBlocks() as $position => $block) {
                (new WorkoutExercise([
                    'workout_session_id' => $session->id,
                    'exercise_id' => $block->exercise->id,
                    'position' => $position + 1,
                ]))->forceFill(['id' => $block->id, 'user_id' => $user->id])->save();

                foreach ($block->sets as $index => $set) {
                    (new WorkoutSet([
                        'workout_session_id' => $session->id,
                        'workout_exercise_id' => $block->id,
                        'exercise_id' => $block->exercise->id,
                        'position' => $index + 1,
                        'type' => $set->type->type(),
                        'variant' => $set->type->variant(),
                        'target_weight_kg' => $set->targetKg,
                        'target_reps' => $set->targetReps,
                        'is_logged' => false,
                        'is_skipped' => false,
                    ]))->forceFill(['id' => $set->id, 'user_id' => $user->id])->save();
                }
            }

            return $session;
        });
    }
}
