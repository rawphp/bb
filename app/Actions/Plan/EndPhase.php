<?php

namespace App\Actions\Plan;

use App\Models\User;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PH-007: end the running phase early, at the next plan-week boundary. The phases after it move up with its end, as
 * when it is shortened (PH-006). When it is the plan's last phase, `plan_show` then offers its transition (PH-030).
 */
final class EndPhase
{
    /** @return list<string> the ids of the phases whose dates changed */
    public function __invoke(User $user, string $phaseId): array
    {
        // The plan is read and checked under its row lock, so a write running at the same time cannot move it meanwhile.
        return DB::transaction(function () use ($user, $phaseId): array {
            $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet.');
            $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
            if (! (new PhaseChanges($plan))->canEnd($phase)) {
                throw new DomainException('Only the phase running now can be ended.');
            }

            return SetPhaseLength::moveEnd($plan, $phase, $plan->dayOfWeek($plan->nextWeek()));
        });
    }
}
