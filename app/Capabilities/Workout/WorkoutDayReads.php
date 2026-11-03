<?php

namespace App\Capabilities\Workout;

use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The reads a session action changes (CAP-011, CAP-017): the session's, as {@see WorkoutReads} names them, and, when
 * the change was written to the mesocycle's day (MESO-045, EX-008, WO-031), those of every session that runs that day
 * from `$fromWeek` on, as the plan numbers them (SCH-039): its workout_show, and Today and the week strip of the day it
 * shows on.
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
            $mesocycle = Mesocycle::query()->where('user_id', $user->id)->findOrFail($session->mesocycle_id);
            $rows = WorkoutSession::query()->where('user_id', $user->id)->where('mesocycle_id', $mesocycle->id)->get();
            // The rotation and the weeks as the plan runs them; slot names are not needed.
            $domain = (new TrainSnapshot(
                today: TrainSnapshot::day(CarbonImmutable::now()),
                plan: null,
                profile: null,
                mesocycles: new Collection([$mesocycle]),
                phases: new Collection,
                sessions: new Collection,
                sets: new Collection,
                feedback: new Collection,
                planWeeks: new Collection,
                dayTargets: new Collection,
                exercises: new Collection,
            ))->domain($mesocycle);
            $dayId = $domain->sessionsInWeek($session->week - 1)[$session->day - 1]->id ?? null;
            for ($week = $fromWeek; $week <= (int) $mesocycle->weeks; $week++) {
                foreach ($domain->sessionsInWeek($week - 1) as $index => $day) {
                    if ($day->id === $dayId) {
                        $row = $rows->first(fn (WorkoutSession $row) => $row->week === $week && $row->day === $index + 1)
                            ?? new WorkoutSession(['mesocycle_id' => $mesocycle->id, 'week' => $week, 'day' => $index + 1]);
                        $keys = [...$keys, ...WorkoutReads::keys($user, $row)];
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
