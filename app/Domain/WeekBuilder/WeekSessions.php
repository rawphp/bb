<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Model\Mesocycle;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Session;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\ScheduledSession;
use App\Domain\Placement\SessionPlacement;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progression\Deload;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Domain\WeekBuilder\WeekSessions\LoggedDay;
use App\Domain\WeekBuilder\WeekSessions\Result;
use App\Domain\WeekBuilder\WeekSessions\SessionRow;
use DateTimeImmutable;

/**
 * The sessions of a plan week, composed once for WeekBuilder and the Today screen: the mesocycle week, the deload
 * rule, the deloaded sessions, the away pause and placement on the training days. Dates are read in the time zone
 * of the week's start.
 */
final class WeekSessions
{
    /**
     * PLAN-022, PLAN-024, SCH-039, SCH-040, SCH-050 to SCH-057, SCH-060.
     *
     * @param  int  $week  The plan week index.
     * @param  int  $mesocycleStartWeek  The plan week the mesocycle's first week falls on.
     * @param  list<TrainingDay>  $trainingDays
     * @param  bool  $flaggedDeload  The stored plan week is flagged a deload.
     * @param  list<LoggedDay>  $logged  The mesocycle's days that have a workout session; they stay after an ended
     *                                   mesocycle's last day.
     * @param  list<int>  $pausedWeeks  The plan weeks that pause training (SCH-060); they do not count as mesocycle weeks.
     * @param  list<SessionRow>  $rows  The mesocycle's stored session rows. A row's start puts its session on that date
     *                                  and time when the date is in this plan week; the others keep their training
     *                                  weekday (SCH-040).
     */
    public static function build(
        PlanWeek $planWeek,
        int $week,
        ?Mesocycle $mesocycle,
        int $mesocycleStartWeek,
        array $trainingDays,
        ?Training $awayTraining = null,
        bool $flaggedDeload = false,
        array $logged = [],
        array $pausedWeeks = [],
        array $rows = [],
    ): Result {
        $mesocycleWeek = PlanRules::mesocycleWeek($week, $mesocycleStartWeek, $pausedWeeks);
        $isLogged = fn (?int $day = null) => array_any(
            $logged,
            fn (LoggedDay $logged) => $logged->week === $mesocycleWeek + 1 && ($day === null || $logged->day === $day),
        );
        // MESO-043: a week that starts after the mesocycle's last day never runs, the deload included, unless
        // something was logged in it.
        $inMesocycle = $mesocycle !== null
            && $mesocycleWeek >= 0 && $mesocycleWeek < $mesocycle->weeks
            && ($mesocycle->lastDay === null || $planWeek->start->setTime(0, 0) <= $mesocycle->lastDay || $isLogged());
        $isPaused = $awayTraining === Training::Pause;
        $isDeload = ! $isPaused
            && ($flaggedDeload
                || ($inMesocycle && ($mesocycleWeek === $mesocycle->weeks - 1 || $awayTraining === Training::Deload)));
        $sessions = $inMesocycle && ! $isPaused
            ? array_map(fn (Session $session) => $isDeload ? Deload::session($session) : $session, $mesocycle->sessionsInWeek($mesocycleWeek))
            : [];

        $onTrainingDays = SessionPlacement::place($sessions, $trainingDays, $planWeek);
        // SCH-050 to SCH-057: a session's row says where it went. A session with a row that starts in this plan week
        // is placed there even when the training days ran out (SCH-039). The list keeps session order, so numbers
        // hold; it ends at the first session with neither a training day nor a row.
        $fallbackLength = $trainingDays[0]->time->lengthMinutes ?? 60;
        $placed = [];
        foreach ($sessions as $index => $session) {
            $row = array_find($rows, fn (SessionRow $row) => $row->week === $mesocycleWeek + 1 && $row->day === $index + 1);
            $start = self::startInWeek($row?->start, $planWeek);
            $onTrainingDay = $onTrainingDays[$index] ?? null;
            if ($onTrainingDay === null && $start === null) {
                break;
            }
            $placed[] = new ScheduledSession(
                date: $start?->setTime(0, 0) ?? $onTrainingDay->date,
                session: $session,
                time: new WorkoutTime(
                    $start === null ? $onTrainingDay->time->start : ClockTime::at((int) $start->format('G'), (int) $start->format('i')),
                    $onTrainingDay?->time->lengthMinutes ?? $fallbackLength,
                ),
                isSkipped: $row->isSkipped ?? false,
            );
        }
        // MESO-043: unstarted sessions after the last day are not planned; logged ones stay. The list is cut after
        // the last session that stays, so the sessions keep their numbers.
        if ($mesocycle?->lastDay !== null) {
            $kept = -1;
            foreach ($placed as $index => $item) {
                if ($item->date <= $mesocycle->lastDay || $isLogged($index + 1)) {
                    $kept = $index;
                }
            }
            $placed = array_slice($placed, 0, $kept + 1);
        }

        return new Result($isDeload, $inMesocycle ? $mesocycleWeek : null, $isPaused, $placed);
    }

    /**
     * The plan for one date; null before the plan's start.
     *
     * @param  list<TrainingDay>  $trainingDays
     * @param  list<LoggedDay>  $logged
     * @param  list<int>  $pausedWeeks
     * @param  list<SessionRow>  $rows
     */
    public static function plan(
        DateTimeImmutable $on,
        PlanWeekGrid $grid,
        ?Mesocycle $mesocycle,
        int $mesocycleStartWeek,
        array $trainingDays,
        ?Training $awayTraining = null,
        bool $flaggedDeload = false,
        array $logged = [],
        array $pausedWeeks = [],
        array $rows = [],
    ): ?DaySessionPlan {
        $week = $grid->weekIndexContaining($on);
        if ($week === null) {
            return null;
        }
        $result = self::build(
            $grid->planWeek($week), $week, $mesocycle, $mesocycleStartWeek, $trainingDays, $awayTraining, $flaggedDeload,
            $logged, $pausedWeeks, $rows,
        );
        $index = array_find_key(
            $result->scheduled,
            fn (ScheduledSession $item) => self::sameDay($item->date, $on),
        );

        return new DaySessionPlan(
            $week, $result->mesocycleWeek, $result->isDeload, $result->isPaused,
            $index === null ? null : $result->scheduled[$index], $index === null ? null : $index + 1,
        );
    }

    /** $start in the week's time zone when it falls on one of the week's days, else null. */
    private static function startInWeek(?DateTimeImmutable $start, PlanWeek $week): ?DateTimeImmutable
    {
        if ($start === null) {
            return null;
        }
        $local = $start->setTimezone($week->start->getTimezone());

        return array_any($week->days(), fn (DateTimeImmutable $day) => self::sameDay($day, $local)) ? $local : null;
    }

    /** True when $date falls on the day $day starts, read in $day's time zone. */
    private static function sameDay(DateTimeImmutable $day, DateTimeImmutable $date): bool
    {
        return $date->setTimezone($day->getTimezone())->format('Y-m-d') === $day->format('Y-m-d');
    }
}
