<?php

namespace App\Capabilities\Workout;

use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Models\Mesocycle;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Workout\WorkoutSnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * The reads a workout write changes (CAP-011, CAP-017): the session's workout_show, the Train tab and its mesocycle,
 * and Today and the week strip (SCH-022) of each day the session shows on: the day the plan or a move put it on, and
 * the day it started, which can be another day or plan week (as missed_session_resolve names them).
 *
 * The days come from the session row and the plan alone: a write runs on every set, so it reads the plan, the profile
 * and the mesocycle, not the mesocycle's sessions and sets a Train snapshot brings.
 */
final class WorkoutReads
{
    /** What a start or a set write declares it changes. */
    public const AFFECTS = ['workout_show', 'today_show', 'week_show', 'train_show', 'mesocycle_show'];

    /** @return list<string> */
    public static function keys(User $user, WorkoutSession $session): array
    {
        $mesocycle = Mesocycle::query()->where('user_id', $user->id)->findOrFail($session->mesocycle_id);
        $train = self::calendarOf($user, $mesocycle);
        $grid = $train->calendar($mesocycle)->grid;
        $days = array_filter([
            WorkoutSnapshot::dateOf($train, $mesocycle, $session->week, $session->day),
            $session->planned_start_at === null ? null : TrainSnapshot::day($session->planned_start_at),
            $session->actual_start_at === null ? null : TrainSnapshot::day($session->actual_start_at),
        ]);
        $weekDates = [];
        foreach ($days as $day) {
            $index = $grid->weekIndexContaining($day);
            foreach ($index === null ? [] : $grid->planWeek($index)->days() as $date) {
                $weekDates[] = $date->format('Y-m-d');
            }
        }

        return [
            self::workout($session),
            ...MesocycleReads::keys((string) $session->mesocycle_id),
            ...TodayReads::keys(array_values(array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $days))),
            ...TodayReads::weekKeys(array_values(array_unique($weekDates))),
        ];
    }

    /** What {@see WorkoutSnapshot::dateOf()} and the plan weeks read: the plan, the profile's training days and the mesocycle. */
    private static function calendarOf(User $user, Mesocycle $mesocycle): TrainSnapshot
    {
        return new TrainSnapshot(
            today: TrainSnapshot::day(CarbonImmutable::now()),
            plan: Plan::query()->where('user_id', $user->id)->first(),
            profile: Profile::query()->where('user_id', $user->id)->first(),
            mesocycles: new Collection([$mesocycle]),
            phases: new Collection,
            sessions: new Collection,
            sets: new Collection,
            feedback: new Collection,
            planWeeks: new Collection,
            dayTargets: new Collection,
            exercises: new Collection,
        );
    }

    public static function workout(WorkoutSession $session): string
    {
        return ReadKey::for('workout_show', ['mesocycle_id' => (string) $session->mesocycle_id, 'week' => $session->week, 'day' => $session->day]);
    }
}
