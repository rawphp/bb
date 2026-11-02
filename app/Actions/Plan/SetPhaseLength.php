<?php

namespace App\Actions\Plan;

use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PH-006: lengthen or shorten a phase to a length `phase_show` offers, settled as the user chose when the new end
 * falls mid-mesocycle (PLAN-021): move the end after the nearest deload, change that mesocycle's length, or keep the
 * end. PLAN-028's move to the event week is the same write. The phases after it move with its end.
 */
final class SetPhaseLength
{
    /** @return array{changed: list<string>, weeks: ?int} the ids of the phases whose dates changed, and the length written */
    public function __invoke(User $user, string $phaseId, ?int $weeks, string $fix): array
    {
        // The plan is read and checked under its row lock, so a write running at the same time cannot move it meanwhile.
        return DB::transaction(function () use ($user, $phaseId, $weeks, $fix): array {
            $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet.');
            $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
            $changes = new PhaseChanges($plan);

            $refusal = $changes->lengthRefusal($phase, $weeks);
            if ($refusal !== null) {
                throw new DomainException($refusal);
            }
            $proposal = $changes->proposeLength($phase, $weeks);
            $end = $proposal->end;
            if ($fix === 'move_end_to_deload') {
                $end = $proposal->moveEnd ?? throw new DomainException('No deload falls within 2 weeks of that end, so it cannot move there.');
            } elseif ($fix === 'change_mesocycle_length') {
                [$placed, $mesocycleWeeks] = $proposal->mesocycleChange ?? throw new DomainException('No mesocycle length puts a deload on that last week.');
                $placed->mesocycle->update(['weeks' => $mesocycleWeeks]);
            }

            return [
                'changed' => self::moveEnd($plan, $phase, $end),
                'weeks' => $end === null ? null : $plan->grid->weekIndexContaining($end) - $plan->weeksOf($phase)->lower,
            ];
        });
    }

    /**
     * Gives a phase a new end, and moves the phases after it by as many days, as the end moved (PH-006, PH-007).
     *
     * @return list<string> the ids of the phases whose dates changed
     */
    public static function moveEnd(PlanSnapshot $plan, Phase $phase, ?DateTimeImmutable $end): array
    {
        $oldEnd = $phase->end_date === null ? null : PlanSnapshot::day($phase->end_date);
        $phase->update(['end_date' => $end?->format('Y-m-d')]);
        $changed = [(string) $phase->id];
        $days = $oldEnd === null || $end === null ? 0 : (int) $oldEnd->diff($end)->format('%r%a');
        if ($days === 0) {
            return $changed;
        }
        foreach ($plan->laterPhases($phase) as $next) {
            $next->update([
                'start_date' => PlanSnapshot::day($next->start_date)->modify("{$days} days")->format('Y-m-d'),
                'end_date' => $next->end_date === null ? null : PlanSnapshot::day($next->end_date)->modify("{$days} days")->format('Y-m-d'),
            ]);
            $changed[] = (string) $next->id;
        }

        return $changed;
    }
}
