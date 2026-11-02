<?php

namespace App\Actions\Plan;

use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use DomainException;

/**
 * PLAN-023: turn a Fat loss or Mini-cut deload week's diet break off for that one week, after the deficit-deload
 * warning, or back on. Only a deload still ahead or under way can change. The week's row keeps every other flag.
 */
final class SetDietBreak
{
    /** @return list<string> the week's days, Y-m-d */
    public function __invoke(User $user, string $weekStart, bool $dietBreak): array
    {
        $plan = PlanSnapshot::load($user) ?? throw new DomainException('There is no plan yet.');
        $start = PlanSnapshot::day($weekStart);
        $week = $plan->grid->isBoundary($start) ? $plan->grid->weekIndexContaining($start) : null;
        $marks = $week === null ? null : $plan->timelineWeek($week);
        if ($marks === null || ! $marks->isDeload) {
            throw new DomainException('That week is not a deload on your plan.');
        }
        if ($marks->isPast) {
            throw new DomainException('That deload is over, so its calories can no longer change.');
        }
        if ($dietBreak ? ! $marks->dietBreakIsOff : ! $marks->canTurnOffDietBreak) {
            throw new DomainException($dietBreak
                ? 'This deload already runs at maintenance calories.'
                : 'Only a Fat loss or Mini-cut deload has a diet break to turn off.');
        }

        $row = $plan->weekRow($week);
        if ($row === null) {
            $row = new PlanWeek(['start_date' => $start->format('Y-m-d'), 'is_deload' => false, 'is_diet_break' => false, 'is_away' => false]);
            $row->user_id = $user->id;
        }
        $row->diet_break_off = ! $dietBreak;
        $row->save();

        return array_map(fn (int $day) => $start->modify("+{$day} days")->format('Y-m-d'), range(0, $marks->dayCount - 1));
    }
}
