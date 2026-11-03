<?php

namespace App\Capabilities\Workout;

use App\Capabilities\Support\ReadKey;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;

/**
 * The reads a session action changes (CAP-011, CAP-017): the session's, as {@see WorkoutReads} names them, and, when
 * the change was written to the mesocycle's day (MESO-045, EX-008, WO-031), the workout_show of every session that
 * runs that day from `$fromWeek` on, as the plan numbers them (SCH-039).
 */
final class WorkoutDayReads
{
    /**
     * @param  ?int  $fromWeek  the first week (from 1) the day changed in; null when only the session changed
     * @return list<string>
     */
    public static function keys(User $user, WorkoutSession $session, ?int $fromWeek): array
    {
        $keys = WorkoutReads::keys($user, $session);
        if ($fromWeek !== null) {
            $train = TrainSnapshot::load($user, (string) $session->mesocycle_id);
            $mesocycle = $train->mesocycle((string) $session->mesocycle_id);
            $domain = $train->domain($mesocycle);
            $dayId = $domain->sessionsInWeek($session->week - 1)[$session->day - 1]->id ?? null;
            for ($week = $fromWeek; $week <= (int) $mesocycle->weeks; $week++) {
                foreach ($domain->sessionsInWeek($week - 1) as $index => $day) {
                    if ($day->id === $dayId) {
                        $keys[] = ReadKey::for('workout_show', ['mesocycle_id' => (string) $session->mesocycle_id, 'week' => $week, 'day' => $index + 1]);
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
