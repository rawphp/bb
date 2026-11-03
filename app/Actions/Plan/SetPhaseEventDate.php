<?php

namespace App\Actions\Plan;

use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PH-043: set a phase's event date, or clear it with null. `phase_show` then re-runs the PH-042 check and the
 * PLAN-028 offer.
 */
final class SetPhaseEventDate
{
    /** @return list<string> the id of the phase changed */
    public function __invoke(User $user, string $phaseId, ?string $eventDate): array
    {
        return DB::transaction(function () use ($user, $phaseId, $eventDate): array {
            $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet.');
            $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
            $phase->update(['event_date' => $eventDate === null ? null : PlanSnapshot::day($eventDate)->format('Y-m-d')]);

            return [(string) $phase->id];
        });
    }
}
