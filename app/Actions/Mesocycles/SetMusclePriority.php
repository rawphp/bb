<?php

namespace App\Actions\Mesocycles;

use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A muscle's priority: on a build (MESO-020), starting from the priorities the build showed (MESO-003), or on a saved
 * mesocycle that has not ended, from its next session (MESO-032). Off (null) is for a saved mesocycle only (MESO-034).
 * Its slots are left out of the rotation ({@see TrainSnapshot::domain()}), so its feedback is not asked either. The
 * slots stay stored, so a priority brings the muscle back at the rotation's sets.
 *
 * Every change to the Off set, either way, first stores the weeks so far as they ran (SCH-039's earlier weeks), so
 * their history stays: this week's sessions done or started keep what they ran with, and the rest take the change
 * ({@see MesocycleRules::changingOff()}).
 */
final class SetMusclePriority
{
    public function __invoke(User $user, string $mesocycleId, MuscleGroup $muscle, ?Priority $priority): Mesocycle
    {
        return DB::transaction(function () use ($user, $mesocycleId, $muscle, $priority) {
            // Two writes take turns: each reads, computes and writes the mesocycle under its row lock.
            Mesocycle::query()->where('user_id', $user->id)->whereKey($mesocycleId)->lockForUpdate()->first();
            $snapshot = TrainSnapshot::load($user, $mesocycleId);
            $mesocycle = $snapshot->mesocycle($mesocycleId);
            $saved = $mesocycle->isSaved();
            if ($priority === null && ! $saved) {
                throw new DomainException('A muscle can be set Off only once the mesocycle is running.');
            }
            $calendar = $saved ? $snapshot->calendar($mesocycle) : null;
            if ($calendar !== null && ! $calendar->isRunning()) {
                throw new DomainException('This mesocycle has ended.');
            }

            $stored = $saved ? ($mesocycle->muscle_priorities ?? []) : $snapshot->buildPriorities($mesocycle);
            if (array_key_exists($muscle->value, $stored) && $stored[$muscle->value] === $priority?->value) {
                return $mesocycle;
            }

            $wasOff = array_key_exists($muscle->value, $stored) && $stored[$muscle->value] === null;
            $history = [];
            if ($calendar !== null && $calendar->hasStarted() && ($priority === null || $wasOff)) {
                $current = $calendar->currentWeek();
                $next = [...$stored, $muscle->value => $priority?->value];
                $off = array_values(array_filter(MuscleGroup::cases(), fn (MuscleGroup $m) => array_key_exists($m->value, $next) && $next[$m->value] === null));
                $history = MesocycleRules::changingOff(
                    $snapshot->rotationAsRun($mesocycle),
                    MesocycleRules::withoutMuscles($mesocycle->dayRows(), $off),
                    $mesocycle->earlierWeekRows(),
                    $mesocycle->laterWeekIds(),
                    $current - 1,
                    $snapshot->sessions
                        ->filter(fn (WorkoutSession $s) => $s->mesocycle_id === $mesocycle->id && $s->week === $current && ($s->actual_start_at !== null || $s->is_skipped))
                        ->map(fn (WorkoutSession $s) => (int) $s->day)->values()->all(),
                );
            }
            $stored[$muscle->value] = $priority?->value;
            $mesocycle->update([...$history, 'muscle_priorities' => $stored]);

            return $mesocycle;
        });
    }
}
