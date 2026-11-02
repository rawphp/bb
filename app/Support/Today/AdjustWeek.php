<?php

namespace App\Support\Today;

use App\Domain\Model\WeighIn;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\Weight\CurrentWeight;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn as WeighInRow;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What the Adjust sheet reads for a day: the stored days of its plan week (NUT-021) and the user's current weight
 * (WT-011), which the move rules take as lean mass, as rp-clone does. Without a plan the week is the day alone.
 */
final readonly class AdjustWeek
{
    /** @param  list<DayPlan>  $week */
    private function __construct(
        public DayPlans $plans,
        public ?DayPlan $selected,
        public array $week,
        public ?float $weightKg,
    ) {}

    public static function load(User $user, string $date): self
    {
        $plan = Plan::query()->where('user_id', $user->id)->first();
        $dates = self::weekDates(DayPlans::day($date), $plan);
        $plans = DayPlans::load($user, $dates);
        $profile = Profile::query()->where('user_id', $user->id)->first();
        $weighIns = WeighInRow::query()->where('user_id', $user->id)->get()
            ->map(fn (WeighInRow $row) => new WeighIn((string) $row->id, DayPlans::instant($row->weighed_at), (float) $row->weight_kg))
            ->values()->all();

        return new self(
            plans: $plans,
            selected: $plans->plan($date),
            week: array_values(array_filter(array_map(fn (string $day) => $plans->plan($day), $dates))),
            weightKg: CurrentWeight::fromWeighIns($weighIns, $profile?->weight_kg, new DateTimeZone('UTC')),
        );
    }

    /** @return list<string> the plan week's days, Y-m-d */
    private static function weekDates(DateTimeImmutable $day, ?Plan $plan): array
    {
        if ($plan === null) {
            return [$day->format('Y-m-d')];
        }
        $grid = new PlanWeekGrid(DayPlans::day($plan->start_date), PlanWeekDays::calendarWeekdayFromIso($plan->review_weekday));
        $index = $grid->weekIndexContaining($day);
        if ($index === null) {
            return [$day->format('Y-m-d')];
        }

        return array_map(fn (DateTimeImmutable $date) => $date->format('Y-m-d'), $grid->planWeek($index)->days());
    }
}
