<?php

namespace App\Actions\Workout;

use App\Capabilities\Support\FieldRefused;
use App\Domain\Mesocycle\DayEdits;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\MesocycleDay;
use App\Support\Workout\OpenWorkout;
use App\Support\Workout\SessionBlocks;
use Illuminate\Support\Facades\DB;

/**
 * EX-008 (rp-clone swap): another exercise of the same muscle takes an exercise's place in a session under way. Sets
 * already logged or skipped stay on the exercise they were done on; the others move, keeping their ids and types, to
 * a block for the new exercise under the client's ULID (CAP-019), right after it, and take the new exercise's targets
 * (PROG-020). With nothing done the old block goes and the new one takes its place; with everything done nothing
 * moves. `$restOfMesocycle` also writes the swap to the day's slot from this week on. A replay of the ULID changes
 * nothing.
 */
final class SwapWorkoutExercise
{
    public function __invoke(User $user, string $blockId, string $newBlockId, int $exerciseId, bool $restOfMesocycle): WorkoutSession
    {
        return DB::transaction(function () use ($user, $blockId, $newBlockId, $exerciseId, $restOfMesocycle) {
            $block = SessionBlocks::block($user, $blockId);
            $session = $block->session;
            $newId = strtolower($newBlockId);
            if (WorkoutExercise::query()->withTrashed()->where('user_id', $user->id)->whereKey($newId)->exists()) {
                return $session;
            }
            $exercise = Exercise::query()->visibleTo($user)->findOrFail($exerciseId);
            $old = $block->exercise;
            if ((int) $exercise->id === (int) $old->id) {
                throw new FieldRefused('exercise_id', 'That is the exercise already in this place.');
            }
            if ($exercise->muscle_group !== $old->muscle_group) {
                throw new FieldRefused('exercise_id', "Swap {$old->name} for another ".strtolower($old->muscle_group->title()).' exercise.');
            }
            $snapshot = SessionBlocks::snapshot($user, $session);

            $sets = $block->sets()->get()->values();
            $undone = $sets->filter(fn (WorkoutSet $set) => ! $set->is_logged && ! $set->is_skipped);
            if ($undone->isNotEmpty()) {
                $blocks = SessionBlocks::blocks($session);
                $index = (int) array_search($block->id, array_map(fn (WorkoutExercise $b) => $b->id, $blocks), true);
                $new = (new WorkoutExercise(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'position' => $index + 1]))
                    ->forceFill(['id' => $newId, 'user_id' => $user->id]);
                $new->save();
                // Each set takes the target of its place among the exercise's sets (rp-clone plannedSets).
                $planned = $snapshot->plannedLoads($exercise, $sets->count());
                foreach ($undone as $place => $set) {
                    $load = $planned[$place] ?? null;
                    $set->update([
                        'workout_exercise_id' => $newId,
                        'exercise_id' => $exercise->id,
                        'target_weight_kg' => $load?->weight === null ? null : $snapshot->kg($load->weight),
                        'target_reps' => $load?->reps,
                        'actual_weight_kg' => null,
                        'actual_reps' => null,
                    ]);
                }
                if ($undone->count() === $sets->count()) {
                    $block->delete();
                    array_splice($blocks, $index, 1, [$new]);
                } else {
                    array_splice($blocks, $index + 1, 0, [$new]);
                    OpenWorkout::renumber($block);
                }
                OpenWorkout::renumber($new);
                SessionBlocks::renumber($blocks);
            }

            if ($restOfMesocycle) {
                MesocycleDay::slots($snapshot->mesocycle, $snapshot->plan()->id, $snapshot->week, fn (array $slots) => DayEdits::swapping($slots, (int) $old->id, (int) $exercise->id));
            }

            return $session;
        });
    }
}
