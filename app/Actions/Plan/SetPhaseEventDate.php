<?php

namespace App\Actions\Plan;

use App\Models\User;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PH-043: set a phase's event date, or clear it with null. `phase_show` then re-runs the PH-042 check and the
 * PLAN-028 offer. A phase that is over keeps its event, and an event cannot be in the past.
 */
final class SetPhaseEventDate
{
    /** @return list<string> the id of the phase changed */
    public function __invoke(User $user, string $phaseId, ?string $eventDate): array
    {
        return DB::transaction(function () use ($user, $phaseId, $eventDate): array {
            $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet.');
            $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
            if ((new PhaseChanges($plan))->detail($phase)->isOver) {
                throw new DomainException('This phase is over, so its event date can no longer change.');
            }
            if ($eventDate !== null && PlanSnapshot::day($eventDate) < $plan->today) {
                throw new DomainException('An event date cannot be in the past.');
            }
            $phase->update(['event_date' => $eventDate === null ? null : PlanSnapshot::day($eventDate)->format('Y-m-d')]);

            return [(string) $phase->id];
        });
    }
}
