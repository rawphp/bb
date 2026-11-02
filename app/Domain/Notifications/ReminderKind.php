<?php

namespace App\Domain\Notifications;

/** The kinds of notification the server sends (NOTIF-001 to NOTIF-006), each with its own switch (NOTIF-007). */
enum ReminderKind: string
{
    case WeighIn = 'weigh_in';
    case Meal = 'meal';
    case Workout = 'workout';
    case WeeklyReview = 'weekly_review';
    case NextMesocycle = 'next_mesocycle';
    case Retarget = 'retarget';

    /** The switch's name in Settings (NOTIF-007). */
    public function title(): string
    {
        return match ($this) {
            self::WeighIn => 'Weigh-ins',
            self::Meal => 'Meals',
            self::Workout => 'Workouts',
            self::WeeklyReview => 'Weekly review',
            self::NextMesocycle => 'Next mesocycle',
            self::Retarget => 'Meal target changes',
        };
    }

    /** NOTIF-002: meal reminders are off until the user turns them on. The rest start on. */
    public function isOnByDefault(): bool
    {
        return $this !== self::Meal;
    }
}
