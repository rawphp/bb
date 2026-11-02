<?php

namespace App\Capabilities\Reads\Progress;

use App\Capabilities\Progress\Data\ProgressBar;
use App\Capabilities\Progress\Data\ReviewCard;
use App\Capabilities\Progress\Data\StepsCard;
use App\Capabilities\Progress\Data\ThisWeek;
use App\Capabilities\Progress\Data\ThisWeekDay;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Progress\ReviewAvailability;
use App\Domain\Progress\StepProgress;
use App\Domain\Review\ReviewTiming;
use App\Domain\Review\StoredPlanWeek;
use App\Models\PlanWeek;
use App\Models\WeighIn;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Progress\ProgressSnapshot;
use App\Support\Today\TodaySnapshot;

/** The This week, review and steps cards (ANA-003, ANA-004, ANA-005, ANA-011). */
final class ProgressWeekPayload
{
    private const LETTERS = [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'T', 5 => 'F', 6 => 'S', 7 => 'S'];

    /**
     * ANA-003, SAFE-003: the 7 days of the plan week with the calories against target; null without a plan. A week
     * with no calorie target has no calories line.
     */
    public static function thisWeek(ProgressSnapshot $snapshot): ?ThisWeek
    {
        if ($snapshot->plan() === null) {
            return null;
        }
        $week = $snapshot->week;
        $today = $week->today();
        $first = $week->weekStart($today);
        $days = [];
        $target = 0.0;
        $consumed = 0.0;
        for ($offset = 0; $offset < 7; $offset++) {
            $day = $first->modify("+{$offset} days");
            $row = $week->dayTarget($day);
            $target += $row->kcal ?? 0;
            if ($day <= $today) {
                $consumed += $week->consumed($day)->kcal ?? 0;
            }
            $days[] = new ThisWeekDay(
                date: $day->format('Y-m-d'),
                letter: self::LETTERS[(int) $day->format('N')],
                day_label: $day->format('j'),
                calories_label: $row === null ? null : (string) (int) round($row->kcal),
                is_complete: $week->isComplete($day),
                is_today: $day == $today,
            );
        }

        return new ThisWeek(
            title: 'This week',
            calories: $target > 0 ? new ProgressBar(
                title: 'Calories this week',
                short_label: 'Cal',
                value_label: (int) round($consumed).'/'.(int) round($target),
                fraction: round(min(1.0, $consumed / $target), 2),
            ) : null,
            days: $days,
            edit_schedule_title: 'Edit schedule',
        );
    }

    /** ANA-004, REV-001, REV-002, SCH-061: "Weekly review available" while the review of the week that ended is open. */
    public static function reviewCard(ProgressSnapshot $snapshot): ?ReviewCard
    {
        $train = $snapshot->week->train;
        $grid = $train->grid();
        if ($grid === null) {
            return null;
        }
        $stored = $train->planWeeks->map(fn (PlanWeek $row) => new StoredPlanWeek(
            start: TrainSnapshot::day($row->start_date),
            isDeload: (bool) $row->is_deload,
            isDietBreak: (bool) $row->is_diet_break,
            isAway: (bool) $row->is_away,
            reviewedAt: TodaySnapshot::instant($row->reviewed_at),
        ))->values()->all();
        $times = $snapshot->weighIns->map(fn (WeighIn $weighIn) => TodaySnapshot::instant($weighIn->weighed_at))->values()->all();

        return ReviewTiming::status($snapshot->today(), $grid, $stored, $times)->isAvailable()
            ? new ReviewCard(ReviewAvailability::CARD_TITLE, 'Open')
            : null;
    }

    /** ANA-005: today's steps against today's range; null hides the card. */
    public static function steps(ProgressSnapshot $snapshot): ?StepsCard
    {
        $progress = StepProgress::make(
            $snapshot->steps, $snapshot->stepPlan(), PlanWeekDays::isoWeekday($snapshot->today(), $snapshot->zone()),
        );
        if ($progress === null) {
            return null;
        }

        return new StepsCard(
            title: 'Steps',
            bar: new ProgressBar(
                title: 'Steps today',
                short_label: 'Steps',
                value_label: number_format($progress->actual).'/'.number_format($progress->targetMax),
                fraction: round($progress->fraction(), 2),
            ),
            target_label: 'Target '.number_format($progress->targetMin).' to '.number_format($progress->targetMax),
        );
    }
}
