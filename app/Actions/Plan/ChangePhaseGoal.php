<?php

namespace App\Actions\Plan;

use App\Domain\Model\PhaseType;
use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PH-009: change a phase's type from the next plan week, as `phase_show` previewed it. A running phase ends there
 * and a new phase of the new type takes its remaining weeks, with the event date when that falls after the switch
 * (PH-043). A phase that has not started changes type in place. The new type's training defaults reach the next
 * mesocycle, and the running mesocycle's progression follows the phase covering each week (PLAN-002), so nothing
 * else is written.
 */
final class ChangePhaseGoal
{
    /** @return list<string> the ids of the phases written */
    public function __invoke(User $user, string $phaseId, PhaseType $type, string $newPhaseId): array
    {
        $plan = PlanSnapshot::load($user) ?? throw new DomainException('There is no plan yet.');
        $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
        $changes = new PhaseChanges($plan);
        if (! in_array($type, $changes->goalTypes($phase), true)) {
            throw new DomainException($phase->type === $type
                ? "This phase is already {$type->title()}."
                : 'This phase is over, so its goal can no longer change.');
        }
        $goal = $changes->goal($phase, $type);
        if ($goal->refusal !== null) {
            throw new DomainException($goal->refusal);
        }

        $from = $plan->dayOfWeek($goal->startWeek);
        $end = $goal->weeks === null ? null : $plan->dayOfWeek($goal->startWeek + $goal->weeks)->format('Y-m-d');

        return DB::transaction(function () use ($user, $phase, $goal, $from, $end, $newPhaseId): array {
            if ($goal->replacesInPlace) {
                $phase->update(['type' => $goal->type, 'end_date' => $end, 'target_rate_percent_per_week' => $goal->ratePercentPerWeek]);

                return [(string) $phase->id];
            }
            $event = $phase->event_date === null ? null : PlanSnapshot::day($phase->event_date);
            $eventMoves = $event !== null && $event >= $from;
            $phase->update(['end_date' => $from->format('Y-m-d'), 'event_date' => $eventMoves ? null : $phase->event_date]);
            $new = new Phase([
                'type' => $goal->type,
                'start_date' => $from->format('Y-m-d'),
                'end_date' => $end,
                'target_rate_percent_per_week' => $goal->ratePercentPerWeek,
                'event_date' => $eventMoves ? $event->format('Y-m-d') : null,
            ]);
            $new->id = $newPhaseId;
            $new->user_id = $user->id;
            $new->save();

            return [(string) $phase->id, $newPhaseId];
        });
    }
}
