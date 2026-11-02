<?php

namespace App\Actions\Plan;

use App\Models\User;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PH-007: end the running phase early, at the next plan-week boundary, which opens the phase transition (PH-030).
 * The phases after it move up with its end, as when it is shortened (PH-006).
 */
final class EndPhase
{
    /** @return list<string> the ids of the phases whose dates changed */
    public function __invoke(User $user, string $phaseId): array
    {
        $plan = PlanSnapshot::load($user) ?? throw new DomainException('There is no plan yet.');
        $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
        if (! (new PhaseChanges($plan))->canEnd($phase)) {
            throw new DomainException('Only the phase running now can be ended.');
        }

        $end = $plan->dayOfWeek($plan->nextWeek());

        return DB::transaction(fn (): array => SetPhaseLength::moveEnd($plan, $phase, $end));
    }
}
