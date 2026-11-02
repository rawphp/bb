<?php

namespace App\Actions\Mesocycles;

use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;

/**
 * A muscle's priority: on a build (MESO-020), starting from the priorities the build showed (MESO-003), or on a saved
 * mesocycle that has not ended, from its next session (MESO-032). Off (null) is for a saved mesocycle only (MESO-034).
 * Its slots are left out of the rotation ({@see TrainSnapshot::domain()}), so its feedback is not asked either. The
 * slots stay stored, so a priority brings the muscle back at the rotation's sets.
 *
 * Every change to the Off set, either way, first stores the weeks before this one as they ran (SCH-039's earlier
 * weeks), so their history stays. This week runs on from the rotation, so a day added later this week reaches it.
 */
final class SetMusclePriority
{
    public function __invoke(User $user, string $mesocycleId, MuscleGroup $muscle, ?Priority $priority): Mesocycle
    {
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
        $history = $calendar !== null && $calendar->hasStarted() && ($priority === null || $wasOff)
            ? MesocycleRules::freezingWeeks($snapshot->rotationAsRun($mesocycle), $mesocycle->earlierWeekRows(), $mesocycle->laterWeekIds(), $calendar->currentWeek() - 1)
            : [];
        $stored[$muscle->value] = $priority?->value;
        $mesocycle->update([...$history, 'muscle_priorities' => $stored]);

        return $mesocycle;
    }
}
