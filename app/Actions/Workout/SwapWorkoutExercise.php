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
use App\Support\Workout\SessionBlocks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * EX-008 (rp-clone swap): another exercise of the same muscle takes an exercise's place in a session under way. Sets
 * already logged or skipped stay on the exercise they were done on; the others move, keeping their ids and types, to
 * a block for the new exercise under the client's ULID (CAP-019), right after it, and take the new exercise's targets
 * at their places (PROG-020). With nothing done, an exercise with no sets too, the old block goes and the new one
 * takes its place; with everything done nothing moves. `$restOfMesocycle` also writes the swap to the day's slot from
 * this week on. A replay of the ULID changes nothing, also once the old block has gone.
 */
final class SwapWorkoutExercise
{
    public function __invoke(User $user, string $blockId, string $newBlockId, int $exerciseId, bool $restOfMesocycle): WorkoutSession
    {
        return DB::transaction(function () use ($user, $blockId, $newBlockId, $exerciseId, $restOfMesocycle) {
            $newId = strtolower($newBlockId);
            SessionBlocks::lock($user, $blockId);
            $made = SessionBlocks::made($user, $newId);
            if ($made !== null) {
                return WorkoutSession::query()->findOrFail($made->workout_session_id);
            }
            try {
                $block = SessionBlocks::block($user, $blockId);
            } catch (ModelNotFoundException $gone) {
                // A swap sent again while the first, which moved every set and removed the block, was running.
                $made = SessionBlocks::made($user, $newId, locked: true);
                if ($made === null) {
                    throw $gone;
                }

                return WorkoutSession::query()->findOrFail($made->workout_session_id);
            }
            $session = $block->session;
            $exercise = Exercise::query()->visibleTo($user)->findOrFail($exerciseId);
            $old = $block->exercise;
            if ((int) $exercise->id === (int) $old->id) {
                throw new FieldRefused('exercise_id', 'That is the exercise already in this place.');
            }
            if ($exercise->muscle_group !== $old->muscle_group) {
                throw new FieldRefused('exercise_id', "Swap {$old->name} for another ".strtolower($old->muscle_group->title()).' exercise.');
            }
            $snapshot = SessionBlocks::snapshot($user, $session);

            $sets = SessionBlocks::sets($block);
            $done = array_filter($sets, fn (WorkoutSet $set) => $set->is_logged || $set->is_skipped);
            $undone = array_filter($sets, fn (WorkoutSet $set) => ! $set->is_logged && ! $set->is_skipped);
            if ($done === [] || $undone !== []) {
                $blocks = SessionBlocks::blocks($session);
                $index = SessionBlocks::indexOf($blocks, $block);
                $new = (new WorkoutExercise(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'position' => $index + 1]))
                    ->forceFill(['id' => $newId, 'user_id' => $user->id]);
                if (! SessionBlocks::create($new)) {
                    return $session;
                }
                // Each set takes the target of its place among the exercise's sets (rp-clone plannedSets).
                $planned = $sets === [] ? [] : $snapshot->plannedLoads($exercise, count($sets));
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
                if ($done === []) {
                    $block->delete();
                    array_splice($blocks, $index, 1, [$new]);
                } else {
                    array_splice($blocks, $index + 1, 0, [$new]);
                    SessionBlocks::renumber(array_values($done));
                }
                SessionBlocks::renumber(array_values($undone));
                SessionBlocks::renumber($blocks);
            }

            if ($restOfMesocycle) {
                MesocycleDay::slots($snapshot, fn (array $slots) => DayEdits::swapping($slots, (int) $old->id, (int) $exercise->id));
            }

            return $session;
        });
    }
}
