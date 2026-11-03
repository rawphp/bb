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
 * SCH-063: mark the plan week in progress as a rough week, or unmark it. The flag is stored and checked here, and
 * WorkoutSnapshot reads it to hold back progression from the week's sessions. The week is named by its plan start or
 * by the start the week strip shows; the row is stored at the plan start. A deload or an away week cannot be marked;
 * unmarking is always allowed.
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
            // The plan week's start, or the start the week strip shows for it, which is earlier when the plan began mid-week.
            $strip = $snapshot->weekStart($today)->format('Y-m-d');
            if (! in_array($input->week_start, [$start, $strip], true)) {
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

            $days = [...array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $grid->planWeek($week)->days()), ...$this->stripDays($snapshot, $today)];
            sort($days);

            return [
                'dates' => array_values(array_unique($days)),
                'toast' => $input->rough ? 'Marked as a rough week' : 'Rough week unmarked',
            ];
        });
    }

    /** @return list<string> the 7 days (Y-m-d) the week strip shows around today */
    private function stripDays(TodaySnapshot $snapshot, DateTimeImmutable $today): array
    {
        $first = $snapshot->weekStart($today);

        return array_map(fn (int $offset) => $first->modify("+{$offset} days")->format('Y-m-d'), range(0, 6));
    }
}
