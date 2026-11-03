<?php

namespace App\Actions\Mesocycles;

use App\Domain\Mesocycle\MesocycleRules;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($user, $mesocycleId, $dayId) {
            // Two writes take turns, and the one that waited computes from the locked row: what the other saved.
            $locked = Mesocycle::query()->where('user_id', $user->id)->whereKey($mesocycleId)->lockForUpdate()->first();
            $snapshot = TrainSnapshot::load($user, $mesocycleId);
            $mesocycle = $snapshot->lockedMesocycle($mesocycleId, $locked);
            $days = $mesocycle->dayRows();

            if (in_array($dayId, array_column($days, 'id'), true)) {
                return $mesocycle;
            }
            $calendar = $snapshot->calendar($mesocycle);
            if (! $calendar->canAddDay()) {
                throw new DomainException('Days can be added only while the mesocycle runs.');
            }

            $weekday = $snapshot->newDayWeekday($mesocycle);
            $fromWeek = $calendar->hasStarted() ? $calendar->currentWeek() - 1 : 0;
            // MESO-034: the weeks it stores keep the Off muscles out, as they ran.
            $ran = MesocycleRules::freezingWeeks($snapshot->rotationAsRun($mesocycle), $mesocycle->earlierWeekRows(), $mesocycle->laterWeekIds(), $fromWeek);
            $added = MesocycleRules::addingDay(
                $days,
                $ran['earlier_weeks'],
                $ran['later_weeks'],
                fromWeek: $fromWeek,
                day: [
                    'id' => $dayId,
                    'label' => $weekday === null ? 'Day '.(count($days) + 1) : MesocycleRules::weekdayName($weekday),
                    'weekday' => $weekday,
                    'slots' => [],
                ],
            );

            $mesocycle->update($added);

            return $mesocycle;
        });
    }
}
