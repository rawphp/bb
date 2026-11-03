<?php

namespace App\Capabilities\Workout;

use App\Models\Mesocycle;
use App\Models\Plan;
use App\Models\Profile;
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
     * @param  bool  $planWeek  also Today on the days left in each session's plan week ({@see WorkoutReads::keys()})
     * @return list<string>
     */
    public static function keys(User $user, WorkoutSession $session, ?int $fromWeek, bool $planWeek = false): array
    {
        if ($fromWeek === null) {
            return WorkoutReads::keys($user, $session, $planWeek);
        }

        $mesocycle = Mesocycle::query()->where('user_id', $user->id)->findOrFail($session->mesocycle_id);
        // One calendar for every session's keys; domain() reads only the mesocycle, so slot names are not needed.
        $calendar = TrainSnapshot::calendarOnly(
            TrainSnapshot::day(CarbonImmutable::now()),
            new Collection([$mesocycle]),
            Plan::query()->where('user_id', $user->id)->first(),
            Profile::query()->where('user_id', $user->id)->first(),
        );
        $keys = WorkoutReads::keysIn($calendar, $mesocycle, $session, $planWeek);
        $rows = WorkoutSession::query()->where('user_id', $user->id)->where('mesocycle_id', $mesocycle->id)->get();
        $domain = $calendar->domain($mesocycle);
        $dayId = $domain->sessionsInWeek($session->week - 1)[$session->day - 1]->id ?? null;
        for ($week = $fromWeek; $week <= (int) $mesocycle->weeks; $week++) {
            foreach ($domain->sessionsInWeek($week - 1) as $index => $day) {
                if ($day->id === $dayId) {
                    $row = $rows->first(fn (WorkoutSession $row) => $row->week === $week && $row->day === $index + 1)
                        ?? new WorkoutSession(['mesocycle_id' => $mesocycle->id, 'week' => $week, 'day' => $index + 1]);
                    $keys = [...$keys, ...WorkoutReads::keysIn($calendar, $mesocycle, $row, $planWeek)];
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
