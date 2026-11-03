<?php

namespace App\Actions\Plan;

use App\Domain\Model\PhaseType;
use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\NewPhases;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PLAN-006: add a phase at the end of the plan, where the last one ends (this week for an empty plan), as `plan_show`'s
 * Add a phase offers it. TBL-010 limits its length and rate (PH-003 to PH-005), a cut's diet-break weeks not counting
 * (PLAN-025), and SAFE-001 refuses a cut while the user is trying to conceive. A null rate takes the type's default
 * (PH-004); training-only mode stores none (PLAN-007).
 */
final class AddPhase
{
    /**
     * `$replay` is the user's phase already saved under `$phaseId`, if any (CAP-019): the add ran before when it is the
     * plan's last phase, of this type, and nothing is written again.
     *
     * @return list<string> the ids of the phase added and of the phase before it, whose "No end" length it takes away
     */
    public function __invoke(User $user, string $phaseId, PhaseType $type, ?int $weeks, ?float $rate, ?Phase $replay = null): array
    {
        // The plan is read and checked under its row lock, so a write running at the same time cannot move it meanwhile.
        return DB::transaction(function () use ($user, $phaseId, $type, $weeks, $rate, $replay): array {
            $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet. Finish onboarding to build one.');
            if ($replay !== null) {
                $last = $plan->phases->last();
                if ($last === null || ! $last->is($replay) || $replay->type !== $type) {
                    throw new DomainException('That phase id is already used.');
                }

                return [(string) $replay->id];
            }
            $new = new NewPhases($plan);
            $rate = $plan->trainingOnly() ? null : ($rate ?? $new->defaultRate($type));
            $refusal = $new->check($type, $weeks, $rate);
            if ($refusal !== null) {
                throw new DomainException($refusal);
            }
            $before = $new->last();
            $start = (int) $new->startWeek();
            $phase = new Phase([
                'type' => $type,
                'start_date' => $plan->dayOfWeek($start)->format('Y-m-d'),
                'end_date' => $weeks === null ? null : $plan->dayOfWeek($start + $weeks)->format('Y-m-d'),
                'target_rate_percent_per_week' => $rate ?? 0.0,
            ]);
            $phase->id = $phaseId;
            $phase->user_id = $user->id;
            $phase->save();

            return $before === null ? [$phaseId] : [(string) $before->id, $phaseId];
        });
    }
}
