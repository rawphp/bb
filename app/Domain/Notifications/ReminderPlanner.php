<?php

namespace App\Domain\Notifications;

use App\Domain\Model\Meal;
use App\Domain\Placement\ClockTime;
use DateTimeImmutable;

/**
 * NOTIF-001 to NOTIF-007: the notifications a user's days call for, as a pure function of those days and the
 * switches. Ported from rp-clone's ReminderPlanner. The phone scheduled a week ahead; the server sends what fell due
 * since its last pass ({@see due()}), so the phone's 7-day horizon and 64-reminder cap do not apply.
 */
final class ReminderPlanner
{
    /** NOTIF-003. */
    public const WORKOUT_LEAD_MINUTES = 60;

    /** NOTIF-006: a change of 0.5 or more in a macro is a re-target, not rounding. */
    public const RETARGET_THRESHOLD = 0.5;

    /** NOTIF-004, NOTIF-005: the weekly review and next mesocycle reminders go out at 09:00. */
    public static function morningTime(): ClockTime
    {
        return ClockTime::at(9);
    }

    /**
     * @param  list<ReminderDay>  $days
     * @return list<PlannedReminder> earliest first
     */
    public static function plan(array $days, ReminderSwitches $switches): array
    {
        $planned = [];
        foreach ($days as $day) {
            $key = $day->date->format('Y-m-d');
            $today = "/today?date={$key}";

            // NOTIF-001, WT-002, NOTIF-011: the reminder opens weigh-in entry.
            if ($switches->isOn(ReminderKind::WeighIn) && $day->weighIn !== null && ! $day->weighIn->isLogged) {
                $planned[] = new PlannedReminder("weigh-in-{$key}", ReminderKind::WeighIn, 'Weigh-in', 'Time to weigh in',
                    self::at($day, $day->weighIn->time), "{$today}&open=weigh-in");
            }
            // NOTIF-002.
            if ($switches->isOn(ReminderKind::Meal)) {
                foreach ($day->meals as $meal) {
                    if (! $meal->isCheckedIn && $meal->time !== null) {
                        $planned[] = new PlannedReminder("meal-{$meal->id}", ReminderKind::Meal, $meal->name, 'Time to eat',
                            self::at($day, $meal->time), $today);
                    }
                }
            }
            // NOTIF-003, NOTIF-011: the reminder opens the workout.
            if ($switches->isOn(ReminderKind::Workout) && $day->workout !== null && ! $day->workout->isDone) {
                $planned[] = new PlannedReminder("workout-{$key}", ReminderKind::Workout, 'Workout in 1 hour',
                    self::workoutBody($day->workout, $day->meals),
                    self::at($day, $day->workout->start)->modify('-'.self::WORKOUT_LEAD_MINUTES.' minutes'), "{$today}&open=workout");
            }
            // NOTIF-004.
            if ($switches->isOn(ReminderKind::WeeklyReview) && $day->hasWeeklyReview) {
                $planned[] = new PlannedReminder("weekly-review-{$key}", ReminderKind::WeeklyReview, 'Weekly review available',
                    'Your week is ready to review', self::at($day, self::morningTime()), $today);
            }
            // NOTIF-005.
            if ($switches->isOn(ReminderKind::NextMesocycle) && $day->startsDeloadWeek) {
                $planned[] = new PlannedReminder("next-mesocycle-{$key}", ReminderKind::NextMesocycle, 'Build your next mesocycle',
                    'It is a deload week. Plan what comes next.', self::at($day, self::morningTime()), $today);
            }
        }

        usort($planned, fn (PlannedReminder $a, PlannedReminder $b) => [$a->fireAt, $a->id] <=> [$b->fireAt, $b->id]);

        return $planned;
    }

    /**
     * The reminders due after `$after` and up to `$upTo`: what fell due since the last pass. One already past that
     * window is dropped, not sent late.
     *
     * @param  list<PlannedReminder>  $planned
     * @return list<PlannedReminder>
     */
    public static function due(array $planned, DateTimeImmutable $after, DateTimeImmutable $upTo): array
    {
        return array_values(array_filter($planned, fn (PlannedReminder $r) => $r->fireAt > $after && $r->fireAt <= $upTo));
    }

    /**
     * NOTIF-006: the notice for a re-target of `$date` that changed a meal the user planned foods for; null otherwise.
     * A meal is matched by id, so one the re-target added or removed is not a changed meal.
     *
     * @param  list<Meal>  $before
     * @param  list<Meal>  $after
     * @param  list<string>  $mealsWithFoods  ids of the meals with planned foods
     */
    public static function retargetNotice(array $before, array $after, array $mealsWithFoods, DateTimeImmutable $date, DateTimeImmutable $now): ?PlannedReminder
    {
        $old = [];
        foreach ($before as $meal) {
            $old[$meal->id] = $meal->target;
        }
        $changed = [];
        foreach ($after as $meal) {
            $was = $old[$meal->id] ?? null;
            if ($was === null || ! in_array($meal->id, $mealsWithFoods, true)) {
                continue;
            }
            $new = $meal->target;
            $pairs = [[$was->kcal, $new->kcal], [$was->proteinG, $new->proteinG], [$was->fatG, $new->fatG], [$was->carbsG, $new->carbsG]];
            foreach ($pairs as [$a, $b]) {
                if (abs($a - $b) >= self::RETARGET_THRESHOLD) {
                    $changed[] = $meal->name;
                    break;
                }
            }
        }
        if ($changed === []) {
            return null;
        }
        $body = count($changed) === 1
            ? "New targets for {$changed[0]}. Check its planned foods."
            : 'New targets for '.implode(', ', $changed).'. Check their planned foods.';
        $key = $date->format('Y-m-d');

        return new PlannedReminder("retarget-{$key}", ReminderKind::Retarget, 'Meal targets changed', $body, $now, "/today?date={$key}");
    }

    /** The day's wall-clock time, so a daylight saving change day keeps the hour the user set. */
    private static function at(ReminderDay $day, ClockTime $time): DateTimeImmutable
    {
        return $day->date->setTime($time->hour(), $time->minute());
    }

    /**
     * NOTIF-003: the session, and the status of the last timed meal at or before its start.
     *
     * @param  list<ReminderMeal>  $meals
     */
    private static function workoutBody(ReminderWorkout $workout, array $meals): string
    {
        $before = null;
        foreach ($meals as $meal) {
            if ($meal->time !== null && $meal->time->minuteOfDay <= $workout->start->minuteOfDay
                && ($before === null || $meal->time->minuteOfDay > $before->time?->minuteOfDay)) {
                $before = $meal;
            }
        }
        if ($before === null) {
            return $workout->name;
        }

        return "{$workout->name} · {$before->name} ".($before->isCheckedIn ? 'checked in' : 'not checked in yet');
    }
}
