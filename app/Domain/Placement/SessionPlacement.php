<?php

namespace App\Domain\Placement;

use App\Domain\Model\PlanWeek;
use App\Domain\Model\Session;

final class SessionPlacement
{
    /**
     * SCH-040: the sessions, in order, onto the week's training days in plan-week order (from the review day), at
     * each day's time. Days beyond the sessions stay free, and sessions beyond the days are not placed. Weekdays are
     * read in the time zone of the week's start.
     *
     * @param  list<Session>  $sessions
     * @param  list<TrainingDay>  $trainingDays
     * @return list<ScheduledSession>
     */
    public static function place(array $sessions, array $trainingDays, PlanWeek $week): array
    {
        $placed = [];
        foreach ($week->days() as $date) {
            if (count($placed) === count($sessions)) {
                break;
            }
            $weekday = (int) $date->format('w') + 1;
            foreach ($trainingDays as $day) {
                if ($day->weekday === $weekday) {
                    $placed[] = new ScheduledSession($date, $sessions[count($placed)], $day->time);
                    break;
                }
            }
        }

        return $placed;
    }
}
