<?php

namespace App\Support\Notifications;

use App\Domain\Notifications\ReminderDay;
use App\Domain\Notifications\ReminderMeal;
use App\Domain\Notifications\ReminderPlanner;
use App\Domain\Notifications\ReminderWeighIn;
use App\Domain\Notifications\ReminderWorkout;
use App\Domain\Placement\ClockTime;
use App\Models\DayTarget;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodayMealItem;
use App\Support\Today\TodaySnapshot;
use App\Support\Today\TodayWorkoutItem;
use DateTimeImmutable;

/**
 * A stored day as the reminder planner reads it (NOTIF-001 to NOTIF-005), from the same snapshot Today shows, so a
 * reminder names what the Today screen has. Ported from rp-clone's TodayModel+Reminders.
 */
final class ReminderDays
{
    /**
     * The days from `$first` to `$last`, both whole days, as Today shows them: a day before the plan or before the
     * profile exists is left out, and so is a day Today would show as another (outside the plan's weeks and not in
     * today's week, SCH-023).
     *
     * @return list<ReminderDay>
     */
    public static function between(User $user, DateTimeImmutable $first, DateTimeImmutable $last): array
    {
        $days = [];
        for ($day = TrainSnapshot::day($first); $day <= TrainSnapshot::day($last); $day = $day->modify('+1 day')) {
            $found = self::on($user, $day);
            if ($found !== null) {
                $days[] = $found;
            }
        }

        return $days;
    }

    /**
     * A cheap check before {@see between()}: whether any clock time a reminder can fall at lies in ($after, $now].
     * It reads every source of a due time, so it may say yes when nothing is due but never no when something is: the
     * 09:00 review and deload reminders, the weigh-in schedule, the meal times of the days' targets, and an hour
     * before each session start, from the profile's training days and from the session rows a move or re-time wrote.
     */
    public static function mayHaveDue(User $user, DateTimeImmutable $after, DateTimeImmutable $now): bool
    {
        $span = $now->getTimestamp() - $after->getTimestamp();
        if ($span >= 86400) {
            return true;
        }
        $first = TrainSnapshot::day($after);
        $last = TrainSnapshot::day($now->modify('+'.ReminderPlanner::WORKOUT_LEAD_MINUTES.' minutes'));
        $lead = ReminderPlanner::WORKOUT_LEAD_MINUTES;

        $times = [ReminderPlanner::morningTime()];
        foreach (WeighInScheduleEntry::query()->where('user_id', $user->id)->pluck('time') as $time) {
            $times[] = ClockTime::fromHhmm((string) $time);
        }
        $targets = DayTarget::query()->where('user_id', $user->id)
            ->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])->pluck('meals');
        foreach ($targets as $meals) {
            foreach (TodaySnapshot::json($meals) as $meal) {
                $times[] = ClockTime::fromHhmm((string) ($meal['time'] ?? ''));
            }
        }
        foreach (TodaySnapshot::json(Profile::query()->where('user_id', $user->id)->value('training_days')) as $day) {
            $times[] = ClockTime::fromHhmm((string) ($day['start'] ?? ''))?->adding(-$lead);
        }
        $rows = WorkoutSession::query()->where('user_id', $user->id)
            ->where('planned_start_at', '>=', $first->format('Y-m-d 00:00:00'))
            ->where('planned_start_at', '<', $last->modify('+1 day')->format('Y-m-d 00:00:00'))
            ->pluck('planned_start_at');
        foreach ($rows as $start) {
            $at = TodaySnapshot::instant($start);
            $times[] = $at === null ? null : ClockTime::at((int) $at->format('G'), (int) $at->format('i'))->adding(-$lead);
        }

        $from = (int) $after->format('G') * 3600 + (int) $after->format('i') * 60 + (int) $after->format('s');
        foreach (array_filter($times) as $time) {
            $offset = (($time->minuteOfDay * 60 - $from) % 86400 + 86400) % 86400;
            if ($offset > 0 && $offset <= $span) {
                return true;
            }
        }

        return false;
    }

    private static function on(User $user, DateTimeImmutable $day): ?ReminderDay
    {
        $snapshot = TodaySnapshot::load($user, $day->format('Y-m-d'));
        $start = $snapshot->planStart();
        // Today shows a day outside the plan at its nearest edge (SCH-023); that is another day.
        if ($snapshot->date != $day || $start === null || $snapshot->isBeforePlan($day) || $snapshot->train->profile === null) {
            return null;
        }
        $weekday = (int) $day->format('N');
        $workout = $snapshot->workout($day);

        return new ReminderDay(
            date: $day,
            weighIn: self::weighIn($snapshot, $weekday),
            meals: array_map(fn (TodayMealItem $meal) => new ReminderMeal($meal->id, $meal->name, $meal->time, $meal->isChecked()), $snapshot->meals($day)),
            workout: $workout === null ? null : new ReminderWorkout($workout->session->name, $workout->time, $workout->state !== TodayWorkoutItem::PLANNED),
            // NOTIF-004: a review day, but not before one full plan week has passed since the plan start.
            hasWeeklyReview: $weekday === (int) $snapshot->train->plan?->review_weekday && $start->diff($day)->days >= 7,
            // NOTIF-005: MESO-060 prompts from the first day of the deload week.
            startsDeloadWeek: $snapshot->isDeload($day) && ! $snapshot->isDeload($day->modify('-1 day')),
        );
    }

    /** WT-001, SCH-004: the day's scheduled weigh-in, and whether one is already logged that day. */
    private static function weighIn(TodaySnapshot $snapshot, int $weekday): ?ReminderWeighIn
    {
        $entry = $snapshot->weighInSchedule->first(fn (WeighInScheduleEntry $e) => in_array($weekday, array_map('intval', $e->weekdays ?? []), true));
        $time = $entry === null ? null : ClockTime::fromHhmm((string) $entry->time);

        return $time === null ? null : new ReminderWeighIn($time, $snapshot->weighIns->isNotEmpty());
    }
}
