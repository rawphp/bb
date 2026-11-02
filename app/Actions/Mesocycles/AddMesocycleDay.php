<?php

namespace App\Actions\Mesocycles;

use App\Domain\Mesocycle\MesocycleRules;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;

/**
 * MESO-045: adds an empty day after a running mesocycle's days, so the days before it, and the sessions stored against
 * them, keep their numbers. It runs in the remaining weeks, from the current one on; the weeks before keep the days
 * they had (SCH-039). It is labelled in the mesocycle's style: the next free training weekday in a weekday split, else
 * "Day n" with no weekday, as rp-clone does when every training weekday is taken. A day id already there changes
 * nothing, so the outbox can retry.
 */
final class AddMesocycleDay
{
    public function __invoke(User $user, string $mesocycleId, string $dayId): Mesocycle
    {
        $snapshot = TrainSnapshot::load($user, $mesocycleId);
        $mesocycle = $snapshot->mesocycle($mesocycleId);
        $days = $mesocycle->dayRows();

        if (in_array($dayId, array_column($days, 'id'), true)) {
            return $mesocycle;
        }
        $calendar = $snapshot->calendar($mesocycle);
        if (! $calendar->canAddDay()) {
            throw new DomainException('Days can be added only while the mesocycle runs.');
        }

        $weekday = $snapshot->newDayWeekday($mesocycle);
        $added = MesocycleRules::addingDay(
            $days,
            $mesocycle->earlierWeekRows(),
            $mesocycle->laterWeekIds(),
            fromWeek: $calendar->hasStarted() ? $calendar->currentWeek() - 1 : 0,
            day: [
                'id' => $dayId,
                'label' => $weekday === null ? 'Day '.(count($days) + 1) : MesocycleRules::weekdayName($weekday),
                'weekday' => $weekday,
                'slots' => [],
            ],
        );

        $mesocycle->update($added);

        return $mesocycle;
    }
}
