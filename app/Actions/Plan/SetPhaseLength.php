<?php

namespace App\Actions\Plan;

use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PH-006: lengthen or shorten a phase to a length `phase_show` offers, settled as the user chose when the new end
 * falls mid-mesocycle (PLAN-021): move the end after the nearest deload, change that mesocycle's length, or keep the
 * end. PLAN-028's move to the event week is the same write. The phases after it move with its end.
 */
final class SetPhaseLength
{
    public const FIXES = ['keep_end', 'move_end_to_deload', 'change_mesocycle_length'];

    /** @return list<string> the ids of the phases whose dates changed */
    public function __invoke(User $user, string $phaseId, ?int $weeks, string $fix): array
    {
        $plan = PlanSnapshot::load($user) ?? throw new DomainException('There is no plan yet.');
        $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
        $changes = new PhaseChanges($plan);

        $refusal = $changes->lengthRefusal($phase, $weeks);
        if ($refusal !== null) {
            throw new DomainException($refusal);
        }
        $proposal = $changes->proposeLength($phase, $weeks);
        $end = $proposal->end;
        $mesocycle = null;
        if ($fix === 'move_end_to_deload') {
            $end = $proposal->moveEnd ?? throw new DomainException('No deload falls within 2 weeks of that end, so it cannot move there.');
        } elseif ($fix === 'change_mesocycle_length') {
            $mesocycle = $proposal->mesocycleChange ?? throw new DomainException('No mesocycle length puts a deload on that last week.');
        }

        $oldEnd = $phase->end_date === null ? null : PlanSnapshot::day($phase->end_date);
        $later = $plan->laterPhases($phase);

        return DB::transaction(function () use ($phase, $end, $mesocycle, $oldEnd, $later): array {
            if ($mesocycle !== null) {
                $mesocycle[0]->mesocycle->update(['weeks' => $mesocycle[1]]);
            }
            $phase->update(['end_date' => $end?->format('Y-m-d')]);
            $changed = [(string) $phase->id];
            $days = $oldEnd === null || $end === null ? 0 : (int) $oldEnd->diff($end)->format('%r%a');
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
