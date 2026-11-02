<?php

namespace App\Capabilities\Workout;

use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Workout\WorkoutSnapshot;
use DateTimeImmutable;

/**
 * The reads a workout write changes (CAP-011, CAP-017): the session's workout_show, the Train tab and its mesocycle,
 * and Today and the week strip (SCH-022) of each day the session shows on: the day the plan or a move put it on, and
 * the day it started, which can be another day or plan week (as missed_session_resolve names them).
 */
final class WorkoutReads
{
    /** What a start or a set write declares it changes. */
    public const AFFECTS = ['workout_show', 'today_show', 'week_show', 'train_show', 'mesocycle_show'];

    /** @return list<string> */
    public static function keys(User $user, WorkoutSession $session): array
    {
        $train = TrainSnapshot::load($user, (string) $session->mesocycle_id);
        $mesocycle = $train->mesocycle((string) $session->mesocycle_id);
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

    public static function workout(WorkoutSession $session): string
    {
        return ReadKey::for('workout_show', ['mesocycle_id' => (string) $session->mesocycle_id, 'week' => $session->week, 'day' => $session->day]);
    }
}
