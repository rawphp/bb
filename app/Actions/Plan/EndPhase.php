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
        $oldEnd = $phase->end_date === null ? null : PlanSnapshot::day($phase->end_date);
        $later = $plan->laterPhases($phase);

        return DB::transaction(function () use ($phase, $end, $oldEnd, $later): array {
            $phase->update(['end_date' => $end->format('Y-m-d')]);
            $changed = [(string) $phase->id];
            $days = $oldEnd === null ? 0 : (int) $oldEnd->diff($end)->format('%r%a');
            if ($days !== 0) {
                foreach ($later as $next) {
                    $next->update([
                        'start_date' => PlanSnapshot::day($next->start_date)->modify("{$days} days")->format('Y-m-d'),
                        'end_date' => $next->end_date === null ? null : PlanSnapshot::day($next->end_date)->modify("{$days} days")->format('Y-m-d'),
                    ]);
                    $changed[] = (string) $next->id;
                }
            }

            return $changed;
        });
    }
}
