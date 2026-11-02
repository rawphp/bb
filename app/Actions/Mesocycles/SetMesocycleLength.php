<?php

namespace App\Actions\Mesocycles;

use App\Capabilities\Reads\Train\TrainSnapshot;
use App\Domain\Mesocycle\MesocycleCalendar;
use App\Models\Mesocycle;
use App\Models\User;
use DomainException;

/**
 * MESO-042: changes a running mesocycle's length. The deload stays the last week, because it is worked out from the
 * length. The lengths allowed are the ones mesocycle_show offers ({@see MesocycleCalendar}).
 */
final class SetMesocycleLength
{
    public function __invoke(User $user, string $mesocycleId, int $weeks): Mesocycle
    {
        $snapshot = TrainSnapshot::load($user, $mesocycleId);
        /** @var Mesocycle $mesocycle */
        $mesocycle = $snapshot->mesocycles->firstWhere('id', $mesocycleId);
        $calendar = $snapshot->calendar($mesocycle);
        $laterWeeksStored = $mesocycle->laterWeekIds() !== [];

        if (! $calendar->canSetWeeks($weeks, $laterWeeksStored)) {
            $range = $calendar->lengthRange();
            throw new DomainException(match (true) {
                ! $calendar->isRunning() => 'This mesocycle is over, so its weeks can\'t change.',
                $calendar->isInDeloadWeek() => 'The deload week has started, so the weeks can\'t change.',
                $laterWeeksStored => 'The weeks were rearranged when your training days changed, so their number can\'t change.',
                default => "This mesocycle is in week {$calendar->currentWeek()}, so it can run {$range->lower} to {$range->upper} weeks.",
            });
        }

        $mesocycle->update(['weeks' => $weeks]);

        return $mesocycle;
    }
}
