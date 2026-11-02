<?php

namespace App\Actions\Mesocycles;

use App\Capabilities\Reads\Train\TrainSnapshot;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * MESO-043: ends a running mesocycle today. Its weeks stay, so the weeks already run keep their targets, and nothing
 * unstarted is planned after today. MESO-044: with a diet break, next plan week becomes one; the phase end does not
 * move (PLAN-025).
 */
final class EndMesocycle
{
    public function __invoke(User $user, string $mesocycleId, bool $dietBreak): Mesocycle
    {
        $snapshot = TrainSnapshot::load($user, $mesocycleId);
        /** @var Mesocycle $mesocycle */
        $mesocycle = $snapshot->mesocycles->firstWhere('id', $mesocycleId);
        $calendar = $snapshot->calendar($mesocycle);

        if (! $calendar->canEnd()) {
            throw new DomainException('Only a mesocycle that has started and is still running can be ended.');
        }
        if ($dietBreak && ! $snapshot->endOffersDietBreak($mesocycle)) {
            throw new DomainException('A diet break is offered only when a fat loss mesocycle ends outside a diet break week.');
        }

        DB::transaction(function () use ($user, $snapshot, $mesocycle, $calendar, $dietBreak) {
            $mesocycle->update(['ended_on' => $snapshot->today->format('Y-m-d')]);
            if (! $dietBreak) {
                return;
            }
            $start = $calendar->nextPlanWeekStart();
            $week = $snapshot->planWeekRow($start) ?? new PlanWeek([
                'start_date' => $start->format('Y-m-d'), 'is_deload' => false, 'is_away' => false,
            ]);
            $week->user_id = $user->id;
            $week->is_diet_break = true;
            $week->save();
        });

        return $mesocycle;
    }
}
