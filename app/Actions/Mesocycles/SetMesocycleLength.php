<?php

namespace App\Actions\Mesocycles;

use App\Domain\Mesocycle\MesocycleCalendar;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;

/**
 * MESO-042: changes a running mesocycle's length. The deload stays the last week, because it is worked out from the
 * length. The lengths allowed are the ones mesocycle_show offers ({@see MesocycleCalendar}). `$expectedWeeks` is the
 * length the user saw: when the mesocycle's length has moved since, the change is refused, so a step from a stale screen
 * never adds or removes weeks the user did not choose.
 */
final class SetMesocycleLength
{
    public function __invoke(User $user, string $mesocycleId, int $weeks, int $expectedWeeks): Mesocycle
    {
        $snapshot = TrainSnapshot::load($user, $mesocycleId);
        $mesocycle = $snapshot->mesocycle($mesocycleId);

        if ($mesocycle->weeks !== $expectedWeeks) {
            throw new DomainException("This mesocycle's length changed to {$mesocycle->weeks} weeks since the screen was loaded, so nothing was changed.");
        }

        $calendar = $snapshot->calendar($mesocycle);
        $laterWeeksStored = $mesocycle->laterWeekIds() !== [];

        if (! $calendar->canSetWeeks($weeks, $laterWeeksStored)) {
            $range = $calendar->lengthRange();
            throw new DomainException(match (true) {
                ! $calendar->isRunning() => 'This mesocycle is over, so its weeks can\'t change.',
                $calendar->isInDeloadWeek() => 'The deload week has started, so the weeks can\'t change.',
                $laterWeeksStored => 'The weeks were rearranged when your training days changed, so their number can\'t change.',
                $weeks > $range->upper && $range->upper < MesocycleCalendar::MAX_WEEKS && $calendar->nextStart !== null => sprintf(
                    'The next mesocycle starts on %s, so this one can run at most %d weeks.', $calendar->nextStart->format('j M'), $range->upper,
                ),
                default => "This mesocycle is in week {$calendar->currentWeek()}, so it can run {$range->lower} to {$range->upper} weeks.",
            });
        }

        $mesocycle->update(['weeks' => $weeks]);

        return $mesocycle;
    }
}
