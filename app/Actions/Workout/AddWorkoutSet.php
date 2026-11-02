<?php

namespace App\Actions\Workout;

use App\Domain\Progression\WorkoutSetType;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\OpenWorkout;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * WO-020, WO-022, CAP-019: adds a set under the client's ULID, below `$afterSetId` or at the end of the exercise.
 * It starts from the set above (rp-clone addSet): that set's weight and reps, as typed or else as targeted, fill the
 * new set's fields, unlogged. Nothing was planned for it, so it has no target (WO-004). The exercise's sets are
 * numbered again.
 */
final class AddWorkoutSet
{
    public function __invoke(User $user, string $setId, string $blockId, ?string $afterSetId, WorkoutSetType $type): WorkoutSession
    {
        return DB::transaction(function () use ($user, $setId, $blockId, $afterSetId, $type) {
            $block = OpenWorkout::block($user, $blockId);
            $existing = WorkoutSet::query()->where('user_id', $user->id)->whereKey($setId)->first();
            if ($existing !== null) {
                // A replay of this create: the set is there already.
                return $block->session;
            }
            $sets = $block->sets()->get()->values();
            $index = $sets->count();
            if ($afterSetId !== null) {
                $found = $sets->search(fn (WorkoutSet $set) => strcasecmp((string) $set->id, $afterSetId) === 0);
                if ($found === false) {
                    throw new DomainException('That set is not in this exercise.');
                }
                $index = $found + 1;
            }
            $above = $index > 0 ? $sets[$index - 1] : null;

            $added = (new WorkoutSet([
                'workout_session_id' => $block->workout_session_id,
                'workout_exercise_id' => $block->id,
                'exercise_id' => $block->exercise_id,
                'position' => $index + 1,
                'type' => $type->type(),
                'variant' => $type->variant(),
                'actual_weight_kg' => $above === null ? null : ($above->actual_weight_kg ?? $above->target_weight_kg),
                'actual_reps' => $above === null ? null : ($above->actual_reps ?? $above->target_reps),
                'is_logged' => false,
                'is_skipped' => false,
            ]))->forceFill(['id' => strtolower($setId), 'user_id' => $user->id]);

            foreach ($sets->slice($index) as $later) {
                $later->update(['position' => $later->position + 1]);
            }
            $added->save();
            OpenWorkout::renumber($block);

            return $block->session;
        });
    }
}
