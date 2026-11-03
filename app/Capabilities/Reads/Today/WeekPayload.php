<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\Today\CalendarRange;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Reads\Data\Today\StripDay;
use App\Capabilities\Reads\Data\Today\WeekAction;
use App\Capabilities\Reads\Data\WeekShowOutput;
use App\Capabilities\Support\Tone;
use App\Support\Today\AwayOffer;
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
        $grid = $snapshot->train->grid();
        $row = $grid === null || $week === null ? null : $snapshot->train->planWeekRow($grid->start($week));
        $rough = $row !== null && $row->is_rough;
        $pills = [];
        if ($snapshot->isDeload($last)) {
            $pills[] = new Status('Deload', Tone::Neutral->value);
        }
        if ($snapshot->isAway($last)) {
            $pills[] = new Status('Away', Tone::Info->value);
        }
        if ($rough) {
            $pills[] = new Status('Rough week', Tone::Warning->value);
        }
        $upper = $snapshot->lastDay();
        $previous = $snapshot->weekShifted($snapshot->date, -7);
        $next = $snapshot->weekShifted($snapshot->date, 7);

        return new WeekShowOutput(
            week_start: $first->format('Y-m-d'),
            heading: $week === null ? null : 'WEEK '.($week + 1),
            pills: $pills,
            days: $days,
            previous_week: $previous?->format('Y-m-d'),
            next_week: $next?->format('Y-m-d'),
            calendar: $snapshot->planStart() === null || $upper === null ? null
                : new CalendarRange($snapshot->planStart()->format('Y-m-d'), $upper->format('Y-m-d')),
            actions: self::actions($snapshot, $week, $last, $rough),
            warnings: [],
        );
    }

    /**
     * SCH-060, SCH-063: what the strip's menu offers for its plan week. This plan week offers to be marked rough, unless
     * it is a deload or an away week; a later week offers to be marked away when the Away sheet would offer it.
     *
     * @return list<WeekAction>
     */
    private static function actions(TodaySnapshot $snapshot, ?int $week, \DateTimeImmutable $last, bool $rough): array
    {
        $grid = $snapshot->train->grid();
        if ($grid === null || $week === null) {
            return [];
        }
        if ($week === $grid->weekIndexContaining($snapshot->today())) {
            return $snapshot->isDeload($last) || $snapshot->isAway($last) ? []
                : [$rough ? new WeekAction('unmark_rough', 'Unmark rough week') : new WeekAction('mark_rough', 'Mark rough week')];
        }
        if (! (AwayOffer::fromTrain($snapshot->train)?->isOffered($week) ?? false)) {
            return [];
        }

        return [$snapshot->isAway($last) ? new WeekAction('edit_away', 'Edit away week') : new WeekAction('mark_away', 'Mark week away')];
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
