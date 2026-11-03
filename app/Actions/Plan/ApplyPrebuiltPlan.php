<?php

namespace App\Actions\Plan;

use App\Domain\Plan\PrebuiltPlan;
use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\NewPhases;
use App\Support\Plan\PlanSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PLAN-030: add one of RP's 24-week plans at the end of the plan, where `plan_show` lays it out. Only its phases are
 * stored; its mesocycles and active rest are built when they start (the active rest is the Maintenance between
 * them). The first phase takes the client's ULID (CAP-019), the rest server ones. Training-only mode stores no rates
 * (PLAN-007).
 */
final class ApplyPrebuiltPlan
{
    /**
     * `$replay` is the user's phase already saved under `$firstPhaseId`, if any: the plan was applied before when it is
     * the first of the last phases the plan would add, and nothing is written again.
     *
     * @return list<string> the ids of the phases added, after the phase before them, whose "No end" length they take away
     */
    public function __invoke(User $user, PrebuiltPlan $prebuilt, string $firstPhaseId, ?Phase $replay = null): array
    {
        return DB::transaction(function () use ($user, $prebuilt, $firstPhaseId, $replay): array {
            $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet. Finish onboarding to build one.');
            if ($replay !== null) {
                return self::replayed($plan, $prebuilt, $replay);
            }
            $new = new NewPhases($plan);
            $refusal = $new->refusal() ?? NewPhases::prebuiltRefusal($plan, $prebuilt);
            if ($refusal !== null) {
                throw new DomainException($refusal);
            }
            $ids = [$firstPhaseId];
            $layout = $prebuilt->layout((int) $new->startWeek(), $plan->grid, function () use (&$ids): string {
                return array_shift($ids) ?? (string) Str::ulid();
            });
            $before = $new->last();
            $added = $before === null ? [] : [(string) $before->id];
            foreach ($layout->phases as $planned) {
                $phase = new Phase([
                    'type' => $planned->type,
                    'start_date' => $planned->start->format('Y-m-d'),
                    'end_date' => $planned->end?->format('Y-m-d'),
                    'target_rate_percent_per_week' => $plan->trainingOnly() ? 0.0 : $planned->targetRatePercentPerWeek,
                ]);
                $phase->id = $planned->id;
                $phase->user_id = $user->id;
                $phase->save();
                $added[] = $planned->id;
            }

            return $added;
        });
    }

    /** @return list<string> */
    private static function replayed(PlanSnapshot $plan, PrebuiltPlan $prebuilt, Phase $replay): array
    {
        $first = $plan->findPhase((string) $replay->id);
        $later = $first === null ? [] : $plan->laterPhases($first);
        $types = array_map(fn ($phase) => $phase->type, $prebuilt->layout(0, $plan->grid, fn () => '')->phases);
        if ($first === null || array_map(fn (Phase $p) => $p->type, [$first, ...$later]) !== $types) {
            throw new DomainException('That phase id is already used.');
        }

        return [(string) $first->id, ...array_map(fn (Phase $p) => (string) $p->id, $later)];
    }
}
