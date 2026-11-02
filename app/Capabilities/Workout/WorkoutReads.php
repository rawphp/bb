<?php

namespace App\Capabilities\Workout;

use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;

/**
 * The reads a workout write changes (CAP-011, CAP-017): the session's workout_show and, when its sets change, the
 * Train tab, its mesocycle and the Today of the day it started.
 */
final class WorkoutReads
{
    /** @return list<string> */
    public static function keys(WorkoutSession $session): array
    {
        return [
            self::workout($session),
            ...MesocycleReads::keys((string) $session->mesocycle_id),
            ...TodayReads::keys($session->actual_start_at === null ? [] : [TrainSnapshot::day($session->actual_start_at)->format('Y-m-d')]),
        ];
    }

    public static function workout(WorkoutSession $session): string
    {
        return ReadKey::for('workout_show', ['mesocycle_id' => (string) $session->mesocycle_id, 'week' => $session->week, 'day' => $session->day]);
    }
}
