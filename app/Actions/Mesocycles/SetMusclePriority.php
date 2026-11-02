<?php

namespace App\Actions\Mesocycles;

use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;

/**
 * A muscle's priority: on a build (MESO-020), starting from the phase's preset the build showed (MESO-003), or on a
 * saved mesocycle that has not ended, from its next session (MESO-032). Off (null) is for a saved mesocycle only
 * (MESO-034). Its slots are left out of the rotation ({@see TrainSnapshot::domain()}), so its feedback is not asked
 * either; the weeks that ran, and the sessions of this week already done or started, are stored as they were first
 * (SCH-039's earlier weeks), so their history stays. The slots stay in the rotation, so a priority brings the muscle
 * back at the rotation's sets.
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
        if ($saved && ! $snapshot->calendar($mesocycle)->isRunning()) {
            throw new DomainException('This mesocycle has ended.');
        }

        $stored = $mesocycle->muscle_priorities ?? [];
        if ($stored === [] && ! $saved) {
            $stored = array_map(fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities($snapshot->buildColumn($mesocycle)));
        }
        if (array_key_exists($muscle->value, $stored) && $stored[$muscle->value] === $priority?->value) {
            return $mesocycle;
        }

        $changes = [];
        if ($priority === null) {
            $changes = $this->keepingHistory($snapshot, $mesocycle, $muscle);
        }
        $stored[$muscle->value] = $priority?->value;
        $mesocycle->update([...$changes, 'muscle_priorities' => $stored]);

        return $mesocycle;
    }

    /**
     * MESO-034 "from the next session": the weeks before this one stored as they ran, and this week too, where the
     * sessions not yet done or started lose the muscle now. Muscles already Off stay out of every stored week.
     *
     * @return array{earlier_weeks?: list<list<array<string, mixed>>>, later_weeks?: list<list<string>>}
     */
    private function keepingHistory(TrainSnapshot $snapshot, Mesocycle $mesocycle, MuscleGroup $muscle): array
    {
        $calendar = $snapshot->calendar($mesocycle);
        if (! $calendar->hasStarted()) {
            return [];
        }

        $off = array_map(fn (MuscleGroup $m) => $m->value, TrainSnapshot::off($mesocycle));
        $without = fn (array $day, array $muscles) => array_replace($day, ['slots' => array_values(array_filter(
            $day['slots'], fn (array $slot) => ! in_array($slot['muscle_group'], $muscles, true),
        ))]);
        $rotation = array_map(fn (array $day) => $without($day, $off), $mesocycle->dayRows());
        $current = $calendar->currentWeek();
        $frozen = MesocycleRules::freezingWeeks($rotation, $mesocycle->earlierWeekRows(), $mesocycle->laterWeekIds(), $current);

        foreach ($frozen['earlier_weeks'][$current - 1] ?? [] as $index => $day) {
            if ($snapshot->loggedSession($mesocycle, $current, $index + 1) === null) {
                $frozen['earlier_weeks'][$current - 1][$index] = $without($day, [$muscle->value]);
            }
        }

        return $frozen;
    }
}
