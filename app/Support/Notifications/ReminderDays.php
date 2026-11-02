<?php

namespace App\Support\Notifications;

use App\Domain\Notifications\ReminderDay;
use App\Domain\Notifications\ReminderKind;
use App\Domain\Notifications\ReminderMeal;
use App\Domain\Notifications\ReminderPlanner;
use App\Domain\Notifications\ReminderWeighIn;
use App\Domain\Notifications\ReminderWorkout;
use App\Domain\Placement\ClockTime;
use App\Models\DayTarget;
use App\Models\NotificationPref;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\ReminderMorning;
use App\Models\SentReminder;
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
     * A cheap check before {@see between()}, for many users at once: which of them have a clock time a reminder can
     * fall at in ($after, $now]. It reads every source of a due time, so it may name a user with nothing due but
     * never leaves out one with something due: the 09:00 review and deload reminders on a plan-week start, the weigh-in schedule, the meal
     * times of the days' targets, and an hour before each session start, from the profile's training days and from
     * the session rows a move or re-time wrote. Four queries, however many users, and four more in the minutes after
     * 09:00.
     *
     * @param  list<int>  $userIds
     * @return list<int> those users who may have something due, in the order given
     */
    public static function mayHaveDue(array $userIds, DateTimeImmutable $after, DateTimeImmutable $now): array
    {
        $span = $now->getTimestamp() - $after->getTimestamp();
        $from = (int) $after->format('G') * 3600 + (int) $after->format('i') * 60 + (int) $after->format('s');
        $inWindow = function (?ClockTime $time) use ($span, $from): bool {
            $offset = $time === null ? 0 : (($time->minuteOfDay * 60 - $from) % 86400 + 86400) % 86400;

            return $offset > 0 && $offset <= $span;
        };
        if ($userIds === [] || $span >= 86400) {
            return $userIds;
        }
        $first = TrainSnapshot::day($after);
        $last = TrainSnapshot::day($now->modify('+'.ReminderPlanner::WORKOUT_LEAD_MINUTES.' minutes'));
        $lead = ReminderPlanner::WORKOUT_LEAD_MINUTES;
        $due = [];

        // NOTIF-004, NOTIF-005: both morning reminders fall on the first day of a plan week, which is the plan start
        // or the review weekday (PLAN-008), and are judged together. A user is not due again once a pass judged that
        // morning (a marker, or a morning reminder sent), nor with both morning switches off (NOTIF-007).
        foreach (self::mornings($after, $now) as $date => $at) {
            $done = [
                ...SentReminder::query()->whereIn('user_id', $userIds)
                    ->whereIn('reminder_id', ["weekly-review-{$date}", "next-mesocycle-{$date}"])->pluck('user_id')->all(),
                ...ReminderMorning::query()->whereIn('user_id', $userIds)->whereDate('date', $date)->pluck('user_id')->all(),
            ];
            $off = NotificationPref::query()->whereIn('user_id', $userIds)
                ->whereIn('kind', [ReminderKind::WeeklyReview->value, ReminderKind::NextMesocycle->value])->where('on', false)
                ->groupBy('user_id')->havingRaw('count(*) = 2')->pluck('user_id')->all();
            $starting = Plan::query()->whereIn('user_id', $userIds)
                ->where(fn ($query) => $query->where('review_weekday', (int) $at->format('N'))->orWhereDate('start_date', $date))
                ->pluck('user_id');
            foreach ($starting as $id) {
                if (! in_array($id, $done, true) && ! in_array($id, $off, true)) {
                    $due[$id] = true;
                }
            }
        }

        foreach (WeighInScheduleEntry::query()->whereIn('user_id', $userIds)->get(['user_id', 'time']) as $entry) {
            if ($inWindow(ClockTime::fromHhmm((string) $entry->time))) {
                $due[$entry->user_id] = true;
            }
        }
        $targets = DayTarget::query()->whereIn('user_id', $userIds)
            ->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])->get(['user_id', 'meals']);
        foreach ($targets as $target) {
            foreach (TodaySnapshot::json($target->meals) as $meal) {
                if ($inWindow(ClockTime::fromHhmm((string) ($meal['time'] ?? '')))) {
                    $due[$target->user_id] = true;
                }
            }
        }
        foreach (Profile::query()->whereIn('user_id', $userIds)->get(['user_id', 'training_days']) as $profile) {
            foreach (TodaySnapshot::json($profile->training_days) as $day) {
                if ($inWindow(ClockTime::fromHhmm((string) ($day['start'] ?? ''))?->adding(-$lead))) {
                    $due[$profile->user_id] = true;
                }
            }
        }
        $rows = WorkoutSession::query()->whereIn('user_id', $userIds)
            ->where('planned_start_at', '>=', $first->format('Y-m-d 00:00:00'))
            ->where('planned_start_at', '<', $last->modify('+1 day')->format('Y-m-d 00:00:00'))
            ->get(['user_id', 'planned_start_at']);
        foreach ($rows as $row) {
            $at = TodaySnapshot::instant($row->planned_start_at);
            if ($at !== null && $inWindow(ClockTime::at((int) $at->format('G'), (int) $at->format('i'))->adding(-$lead))) {
                $due[$row->user_id] = true;
            }
        }

        return array_values(array_filter($userIds, fn (int $id) => isset($due[$id])));
    }

    /**
     * The 09:00 morning instants in ($after, $now], keyed by their Y-m-d.
     *
     * @return array<string, DateTimeImmutable>
     */
    public static function mornings(DateTimeImmutable $after, DateTimeImmutable $now): array
    {
        $morning = ReminderPlanner::morningTime();
        $found = [];
        foreach ([TrainSnapshot::day($after), TrainSnapshot::day($now)] as $day) {
            $at = $day->setTime($morning->hour(), $morning->minute());
            if ($at > $after && $at <= $now) {
                $found[$day->format('Y-m-d')] = $at;
            }
        }

        return $found;
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
