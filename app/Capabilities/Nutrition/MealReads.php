<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use App\Models\DayTargetMeal;
use App\Support\Today\DayPlans;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * The reads a change to one meal invalidates (CAP-010, CAP-011): its meal screen, the Nutrition tab of its day, and
 * Today and the week strip, which show its planned foods and check-in. A day that is today also drops the reads
 * without a date. The day and the plan are read through the meal's dayTarget and user->plan relations.
 */
final class MealReads
{
    public const AFFECTS = ['meal_show', 'nutrition_show', 'today_show', 'week_show'];

    /** @return list<string> */
    public static function keys(DayTargetMeal $meal): array
    {
        $day = DayPlans::day($meal->dayTarget->date);
        $date = $day->format('Y-m-d');
        $keys = [ReadKey::for('meal_show', ['meal_id' => (string) $meal->getKey()]), ReadKey::for('nutrition_show', ['date' => $date])];
        if ($date === Carbon::now()->format('Y-m-d')) {
            $keys[] = ReadKey::for('nutrition_show');
        }

        return [...$keys, ...TodayReads::keys([$date]), ...TodayReads::weekKeys(self::weekDates($meal, $day))];
    }

    /** @return list<string> the days of the meal's plan week, Y-m-d; its own day alone outside a plan */
    private static function weekDates(DayTargetMeal $meal, DateTimeImmutable $day): array
    {
        $plan = $meal->user->plan;
        $grid = $plan === null ? null : new PlanWeekGrid(DayPlans::day($plan->start_date), PlanWeekDays::calendarWeekdayFromIso($plan->review_weekday));
        $index = $grid?->weekIndexContaining($day);
        if ($index === null) {
            return [$day->format('Y-m-d')];
        }

        return array_map(fn (DateTimeImmutable $date) => $date->format('Y-m-d'), $grid->planWeek($index)->days());
    }
}
