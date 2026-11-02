<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\Today\CalendarRange;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Reads\Data\Today\StripDay;
use App\Capabilities\Reads\Data\WeekShowOutput;
use App\Capabilities\Support\Tone;
use App\Support\Today\TodaySnapshot;
use App\Support\Today\TodayWorkoutItem;

/** The week strip above Today's timeline (rp-clone TodayModel and +Timeline, SCH-020 to SCH-023). */
final class WeekPayload
{
    private const LETTERS = [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'T', 5 => 'F', 6 => 'S', 7 => 'S'];

    public static function build(TodaySnapshot $snapshot): WeekShowOutput
    {
        $first = $snapshot->weekStart($snapshot->date);
        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $days[] = self::day($snapshot, $first->modify("+{$offset} days"));
        }
        // The strip's last day is always in its plan week, even when the first days come before the plan.
        $last = $first->modify('+6 days');
        $week = $snapshot->planning($last)['plan']->weekIndex ?? null;
        $pills = [];
        if ($snapshot->isDeload($last)) {
            $pills[] = new Status('Deload', Tone::Neutral->value);
        }
        if ($snapshot->isAway($last)) {
            $pills[] = new Status('Away', Tone::Info->value);
        }
        $lower = $snapshot->firstDay();
        $upper = $snapshot->lastDay();
        $previous = $first->modify('-7 days');
        $next = $first->modify('+7 days');

        return new WeekShowOutput(
            week_start: $first->format('Y-m-d'),
            heading: $week === null ? null : 'WEEK '.($week + 1),
            pills: $pills,
            days: $days,
            previous_week: $lower === null || $snapshot->weekStart($lower) > $previous ? null : max($previous, $lower)->format('Y-m-d'),
            next_week: $upper === null || $next > $upper ? null : $next->format('Y-m-d'),
            calendar: $snapshot->planStart() === null || $upper === null ? null
                : new CalendarRange($snapshot->planStart()->format('Y-m-d'), $upper->format('Y-m-d')),
            actions: [],
            warnings: [],
        );
    }

    /** SCH-020 to SCH-022: a day's calories, the green dot and the dumbbell; "–" before the plan. */
    private static function day(TodaySnapshot $snapshot, \DateTimeImmutable $day): StripDay
    {
        $beforePlan = $snapshot->isBeforePlan($day);
        $target = $beforePlan ? null : $snapshot->dayTarget($day);
        $workout = $beforePlan ? null : $snapshot->workout($day);

        return new StripDay(
            date: $day->format('Y-m-d'),
            letter: self::LETTERS[(int) $day->format('N')],
            number: $day->format('j'),
            calories_text: $target === null ? '–' : (string) (int) round($target->kcal),
            is_today: $day == $snapshot->today(),
            is_selected: $day == $snapshot->date,
            is_complete: ! $beforePlan && $snapshot->isComplete($day),
            has_training: $workout !== null && $workout->state !== TodayWorkoutItem::SKIPPED,
            is_before_plan: $beforePlan,
        );
    }
}
