<?php

namespace App\Actions\Mesocycles;

use App\Capabilities\Reads\Train\MesocyclePayload;
use App\Capabilities\Reads\Train\TrainSnapshot;
use App\Domain\Progress\PlanWeekDays;
use App\Models\Mesocycle;
use App\Models\User;
use DomainException;

/**
 * MESO-045: adds an empty day after a running mesocycle's days, so the days before it, and the sessions stored against
 * them, keep their numbers. It is labelled in the mesocycle's style: the next free training weekday when every day has
 * a weekday, else "Day n". Every week runs it, the later weeks stored too (SCH-039). A day id already there changes
 * nothing, so the outbox can retry.
 */
final class AddMesocycleDay
{
    public function __invoke(User $user, string $mesocycleId, string $dayId): Mesocycle
    {
        $snapshot = TrainSnapshot::load($user, $mesocycleId);
        /** @var Mesocycle $mesocycle */
        $mesocycle = $snapshot->mesocycles->firstWhere('id', $mesocycleId);
        $days = $mesocycle->dayRows();

        if (in_array($dayId, array_column($days, 'id'), true)) {
            return $mesocycle;
        }
        if (! $snapshot->calendar($mesocycle)->canAddDay()) {
            throw new DomainException('Days can be added only while the mesocycle runs.');
        }

        $weekday = $this->freeWeekday($snapshot, $mesocycle, $days);
        $days[] = [
            'id' => $dayId,
            'label' => $weekday === null ? 'Day '.(count($days) + 1) : MesocyclePayload::weekdayName($weekday),
            'weekday' => $weekday,
            'slots' => [],
        ];

        $mesocycle->update([
            'days' => $days,
            'later_weeks' => array_map(fn (array $ids) => [...$ids, $dayId], $mesocycle->laterWeekIds()),
        ]);

        return $mesocycle;
    }

    /**
     * The first training weekday, in plan-week order, no day uses; null for a floating split.
     *
     * @param  list<array{weekday: ?int}>  $days
     */
    private function freeWeekday(TrainSnapshot $snapshot, Mesocycle $mesocycle, array $days): ?int
    {
        if ($days === [] || ! array_all($days, fn (array $day) => $day['weekday'] !== null)) {
            return null;
        }
        $used = array_column($days, 'weekday');
        $ordered = PlanWeekDays::inPlanWeekOrder(
            $snapshot->trainingWeekdays(),
            $snapshot->reviewIsoWeekday($snapshot->calendar($mesocycle)->grid),
        );

        return array_find($ordered, fn (int $weekday) => ! in_array($weekday, $used, true));
    }
}
