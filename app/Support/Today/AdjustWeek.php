<?php

namespace App\Support\Today;

use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use DateTimeImmutable;
use DomainException;

/**
 * What the Adjust sheet reads for a day: the stored days of its plan week (NUT-021) and the user's current weight
 * (WT-011), which the move rules take as lean mass, as rp-clone does. Without a plan the week is the day alone.
 */
final readonly class AdjustWeek
{
    public const NO_DAY = 'This day has no targets to adjust yet.';

    /**
     * @param  list<DayPlan>  $week
     * @param  list<string>  $dates  every day of the plan week, Y-m-d, stored or not
     */
    private function __construct(
        public DayPlans $plans,
        public ?DayPlan $selected,
        public array $week,
        public array $dates,
        public ?float $weightKg,
    ) {}

    public static function load(User $user, string $date): self
    {
        $plan = Plan::query()->where('user_id', $user->id)->first();
        $dates = self::weekDates(DayPlans::day($date), $plan);
        $plans = DayPlans::load($user, $dates);

        return new self(
            plans: $plans,
            selected: $plans->plan($date),
            week: array_values(array_filter(array_map(fn (string $day) => $plans->plan($day), $dates))),
            dates: $dates,
            weightKg: TodayWeight::kg($user, Profile::query()->where('user_id', $user->id)->first()),
        );
    }

    /**
     * DAY-020: the move the sheet's choices come to, today being `$today`. The sheet and the save both ask here, so a
     * move is refused in the same words by both.
     *
     * @throws DomainException when the day has no stored target
     */
    public function outcome(string $mode, ?int $kcal, ?string $fromMealId, ?string $toMealId, ?string $otherDate, string $direction, DateTimeImmutable $today): AdjustOutcome
    {
        $day = $this->selected ?? throw new DomainException(self::NO_DAY);

        return Adjustment::outcome($day, $this->week, $mode, $kcal, $fromMealId, $toMealId, $otherDate, $direction, $today, $this->weightKg);
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
