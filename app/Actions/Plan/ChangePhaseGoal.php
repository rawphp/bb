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
    /**
     * `$replay` is the user's phase already saved under `$newPhaseId`, if any (CAP-019): the change ran before when
     * that is the phase it split off, and nothing is written again.
     *
     * @return list<string> the ids of the phases written
     */
    public function __invoke(User $user, string $phaseId, PhaseType $type, string $newPhaseId, ?Phase $replay = null): array
    {
        // The plan is read and checked under its row lock, so a write running at the same time cannot move it meanwhile.
        return DB::transaction(function () use ($user, $phaseId, $type, $newPhaseId, $replay): array {
            $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet.');
            $phase = $plan->findPhase($phaseId) ?? throw new DomainException('That phase is not on your plan.');
            if ($replay !== null) {
                return self::replayed($plan, $phase, $plan->findPhase((string) $replay->id), $type);
            }
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

    /**
     * CAP-019: the saved phase is this change's split: another phase, of the new type, starting where the changed
     * phase now ends, no later than next plan week (a split always starts on the plan week after the change).
     *
     * @return list<string>
     */
    private static function replayed(PlanSnapshot $plan, Phase $changed, ?Phase $split, PhaseType $type): array
    {
        $start = $split === null ? null : PlanSnapshot::day($split->start_date);
        $isSplit = $split !== null && ! $split->is($changed) && $split->type === $type && $changed->end_date !== null
            && PlanSnapshot::day($changed->end_date) == $start && $start <= $plan->dayOfWeek($plan->nextWeek());
        if (! $isSplit) {
            throw new DomainException('That new phase id is already used.');
        }

        return [(string) $changed->id, (string) $split->id];
    }
}
