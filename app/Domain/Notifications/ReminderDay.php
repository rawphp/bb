<?php

namespace App\Domain\Notifications;

use DateTimeImmutable;

/** What the planner reads about one day: plain values derived from the stored plan (NOTIF-001 to NOTIF-005). */
final readonly class ReminderDay
{
    /**
     * @param  DateTimeImmutable  $date  the day at midnight, in the time zone its wall-clock times are in
     * @param  list<ReminderMeal>  $meals
     * @param  bool  $hasWeeklyReview  NOTIF-004: a review day with a full plan week behind it
     * @param  bool  $startsDeloadWeek  NOTIF-005: the first day of a deload week
     */
    public function __construct(
        public DateTimeImmutable $date,
        public ?ReminderWeighIn $weighIn = null,
        public array $meals = [],
        public ?ReminderWorkout $workout = null,
        public bool $hasWeeklyReview = false,
        public bool $startsDeloadWeek = false,
    ) {}
}
