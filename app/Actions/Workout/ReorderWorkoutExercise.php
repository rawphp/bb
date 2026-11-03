<?php

namespace App\Actions\Workout;

use App\Capabilities\Support\FieldRefused;
use App\Domain\Mesocycle\DayEdits;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Support\Workout\MesocycleDay;
use App\Support\Workout\SessionBlocks;
use Illuminate\Support\Facades\DB;

/**
 * WO-022 Move up and Move down, WO-031 Reorder exercises (rp-clone moveExercise): puts an exercise of a session under
 * way at `$position` (from 1). With `$remainingWeeks` (MESO-045) the day's slots follow the session's new order from
 * this week on. A move to the place it has changes nothing.
 */
final class ReorderWorkoutExercise
{
    public function __invoke(User $user, string $blockId, int $position, bool $remainingWeeks): WorkoutSession
    {
        $mesocycleId = SessionBlocks::mesocycleOf($user, $blockId);

        return DB::transaction(function () use ($user, $blockId, $position, $remainingWeeks, $mesocycleId) {
            SessionBlocks::lock($mesocycleId);
            $block = SessionBlocks::block($user, $blockId);
            $session = $block->session;
            $blocks = SessionBlocks::blocks($session);
            $count = count($blocks);
            if ($position > $count) {
                throw new FieldRefused('position', $count === 1
                    ? 'This session has one exercise, so it stays where it is.'
                    : "This session has {$count} exercises, so pick a place from 1 to {$count}.");
            }
            $from = SessionBlocks::indexOf($blocks, $block);
            if ($from === $position - 1) {
                return $session;
            }
            [$moved] = array_splice($blocks, $from, 1);
            array_splice($blocks, $position - 1, 0, [$moved]);
            SessionBlocks::renumber($blocks);

            if ($remainingWeeks) {
                $snapshot = SessionBlocks::snapshot($user, $session);
                $order = array_map(fn (WorkoutExercise $b) => (int) $b->exercise_id, $blocks);
                MesocycleDay::slots($snapshot, fn (array $slots) => DayEdits::following($slots, $order));
            }

            return $session;
        });
    }
}
