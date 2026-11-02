<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\RoughWeekSetInput;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SCH-063: mark the plan week in progress as a rough week, or unmark it. The flag is stored and checked here and
 * nothing else reads it yet. A deload or an away week cannot be marked; unmarking is always allowed.
 */
final class SetRoughWeek
{
    /** @return array{dates: list<string>, toast: string} the week's days, Y-m-d, and what to say */
    public function __invoke(User $user, RoughWeekSetInput $input): array
    {
        // The plan is locked first, so two writes to its weeks run one after the other and cannot both add the row.
        return DB::transaction(function () use ($user, $input): array {
            Plan::query()->where('user_id', $user->id)->lockForUpdate()->first() ?? throw new DomainException('There is no plan yet.');
            $snapshot = TodaySnapshot::loadWeek($user);
            $grid = $snapshot->train->grid() ?? throw new DomainException('There is no plan yet.');
            $today = $snapshot->today();
            $week = $grid->weekIndexContaining($today) ?? throw new DomainException('Your plan has not started yet.');
            $start = $grid->start($week)->format('Y-m-d');
            if ($input->week_start !== $start) {
                throw new DomainException('Only the plan week in progress can be marked as a rough week.');
            }
            if ($input->rough && ($snapshot->isDeload($today) || $snapshot->isAway($today))) {
                throw new DomainException('A deload or an away week cannot be a rough week.');
            }

            $row = $snapshot->train->planWeekRow($grid->start($week));
            if ($row === null) {
                $row = new PlanWeek(['start_date' => $start, 'is_deload' => false, 'is_diet_break' => false, 'is_away' => false]);
                $row->user_id = $user->id;
            }
            $row->is_rough = $input->rough;
            $row->save();

            return [
                'dates' => array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $grid->planWeek($week)->days()),
                'toast' => $input->rough ? 'Marked as a rough week' : 'Rough week unmarked',
            ];
        });
    }
}
