<?php

namespace App\Domain\Retarget;

use App\Domain\Defaults\TBL012TrainingDefaults;
use App\Domain\Mesocycle\Exercise;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Session;
use App\Domain\Placement\TrainingDay;
use Closure;

/**
 * Changing training weekdays mid-mesocycle (SCH-039). The new weekdays apply from the next plan week, `fromWeek`,
 * the mesocycle week (0 is its first) that week falls on. This plan week's sessions stay on their days; its
 * unchecked days have their meals placed again and re-targeted with the new session times and eating window.
 */
final class TrainingWeekdays
{
    /**
     * SCH-039: the same number of days re-maps; fewer or more offers the two choices.
     *
     * @param  list<TrainingDay>  $old
     * @param  list<TrainingDay>  $new
     */
    public static function change(Mesocycle $mesocycle, array $old, array $new, int $fromWeek): WeekdayChange
    {
        $days = count(array_unique(array_map(fn (TrainingDay $day) => $day->weekday, $new)));
        if ($days === count(array_unique(array_map(fn (TrainingDay $day) => $day->weekday, $old)))) {
            return WeekdayChange::remap();
        }
        // Only the muscles' frequency is read from the rebuilt weeks, so their ids are never used.
        $restructured = self::restructured($mesocycle, $days, $fromWeek, [], fn () => '');

        return WeekdayChange::choose([
            WeekdayChoice::restructure(self::underTrained($restructured, $fromWeek)),
            WeekdayChoice::keepAllSessions(self::keepingAllSessions($mesocycle, $days, $fromWeek)->weeks),
        ]);
    }

    /**
     * SCH-039 "restructure from next week": from `$fromWeek`, the deload included, the Meso Builder's sessions for
     * `$daysPerWeek` (MESO-022, MESO-023) with the same muscle priorities. Each muscle keeps the user's exercise for
     * it and its weekly sets in the rotation, split evenly over its new days (earlier days take the remainder);
     * slots go in MESO-046 order. The length is unchanged.
     *
     * @param  list<Exercise>  $exercises
     * @param  Closure(): string  $newId  a fresh id for each new session and slot
     */
    public static function restructured(Mesocycle $mesocycle, int $daysPerWeek, int $fromWeek, array $exercises, Closure $newId): Mesocycle
    {
        if ($fromWeek >= $mesocycle->weeks) {
            return $mesocycle;
        }
        $slots = array_merge(...array_map(fn (Session $session) => $session->slots, $mesocycle->sessions));
        $sessions = MesoBuilder::sessions($daysPerWeek, $mesocycle->musclePriorities, $mesocycle->trainingDefaultsColumn(), $newId);

        foreach (array_keys($mesocycle->musclePriorities) as $key) {
            $muscle = MuscleGroup::from($key);
            $own = array_values(array_filter($slots, fn (ExerciseSlot $slot) => $slot->muscle === $muscle));
            $exercise = $own[0]->exerciseName ?? '';
            $weekly = array_sum(array_map(fn (ExerciseSlot $slot) => $slot->sets, $own));
            $days = array_keys(array_filter($sessions, fn (Session $session) => self::trains($session, $muscle)));
            foreach ($days as $position => $day) {
                $sets = intdiv($weekly, count($days)) + ($position < $weekly % count($days) ? 1 : 0);
                $sessions[$day] = new Session($sessions[$day]->id, $sessions[$day]->name, array_map(
                    fn (ExerciseSlot $slot) => $slot->muscle === $muscle ? new ExerciseSlot($slot->id, $exercise, $muscle, $sets) : $slot,
                    $sessions[$day]->slots,
                ));
            }
        }

        return new Mesocycle(
            id: $mesocycle->id,
            phase: $mesocycle->phase,
            weeks: $mesocycle->weeks,
            sessions: array_map(fn (Session $session) => MesoBuilder::orderSession($session, $mesocycle->musclePriorities, $exercises), $sessions),
            musclePriorities: $mesocycle->musclePriorities,
            isResensitization: $mesocycle->isResensitization,
            earlierWeeks: self::weeksBefore($mesocycle, $fromWeek),
            laterWeeks: [],
            lastDay: $mesocycle->lastDay,
        );
    }

    /**
     * SCH-039 "keep all sessions": from `$fromWeek`, each week before the deload finishes up to `$daysPerWeek` of the
     * remaining sessions, in order, so the mesocycle runs longer; the deload stays the last week. With as many days
     * as every week's sessions (more days), nothing changes and the extra weekdays stay free.
     */
    public static function keepingAllSessions(Mesocycle $mesocycle, int $daysPerWeek, int $fromWeek): Mesocycle
    {
        $days = max(1, $daysPerWeek);
        $remaining = [];
        for ($week = $fromWeek; $week < max($fromWeek, $mesocycle->weeks - 1); $week++) {
            $remaining[] = $mesocycle->sessionsInWeek($week);
        }
        if (! array_any($remaining, fn (array $sessions) => count($sessions) > $days)) {
            return $mesocycle;
        }

        $queue = array_map(fn (Session $session) => $session->id, array_merge(...$remaining));
        $laterWeeks = array_chunk($queue, $days);

        return new Mesocycle(
            id: $mesocycle->id,
            phase: $mesocycle->phase,
            weeks: $fromWeek + count($laterWeeks) + 1,
            sessions: $mesocycle->sessions,
            musclePriorities: $mesocycle->musclePriorities,
            isResensitization: $mesocycle->isResensitization,
            earlierWeeks: self::weeksBefore($mesocycle, $fromWeek),
            laterWeeks: $laterWeeks,
            lastDay: $mesocycle->lastDay,
        );
    }

    /**
     * MESO-023 re-checked: the prioritised muscles trained on fewer days of `$week` than the phase's minimum
     * frequency (TBL-012), in `MuscleGroup` order.
     *
     * @return list<MuscleGroup>
     */
    public static function underTrained(Mesocycle $mesocycle, int $week): array
    {
        $minimum = TBL012TrainingDefaults::row($mesocycle->trainingDefaultsColumn())->minimumFrequencyPerWeek;
        $sessions = $mesocycle->sessionsInWeek($week);

        return array_values(array_filter(
            MuscleGroup::cases(),
            fn (MuscleGroup $muscle) => isset($mesocycle->musclePriorities[$muscle->value])
                && count(array_filter($sessions, fn (Session $session) => self::trains($session, $muscle))) < $minimum,
        ));
    }

    /**
     * SCH-039: this plan week's days still to come, re-targeted with the new schedule settings (SCH-050):
     * `$trainingDays` for the session times, `$context` for the eating window and busy periods. Sessions stay on
     * their days; a day whose weekday is no longer a training day keeps its session's time. A day with a checked-in
     * meal never changes and is left out of the result. A day's weekday is read in the time zone of its date.
     *
     * @param  list<RetargetDay>  $days
     * @param  list<TrainingDay>  $trainingDays
     * @param  Closure(): string  $newId  an id for the intra-workout shake a session may earn
     *
     * @throws RetargetRefused
     */
    public static function retargetThisWeek(array $days, array $trainingDays, RetargetContext $context, Closure $newId): MealRetarget
    {
        $unchecked = array_values(array_filter(
            $days,
            fn (RetargetDay $day) => ! array_any($day->meals, fn (RetargetMeal $meal) => $meal->isCheckedIn),
        ));
        $result = [];
        foreach ($unchecked as $day) {
            $weekday = (int) $day->day->date->format('w') + 1;
            $session = $day->session;
            foreach ($trainingDays as $trainingDay) {
                if ($trainingDay->weekday === $weekday) {
                    $session = $session?->startingAt($trainingDay->time->start);
                    break;
                }
            }
            $result[] = Retargeting::placed($day, $session, $context, $newId);
        }

        return new MealRetarget(days: $result, undo: $unchecked, message: 'Meals updated for your new schedule');
    }

    /** @return list<list<Session>> the mesocycle's weeks 0 up to $week, as they run */
    private static function weeksBefore(Mesocycle $mesocycle, int $week): array
    {
        $weeks = [];
        for ($index = 0; $index < $week; $index++) {
            $weeks[] = $mesocycle->sessionsInWeek($index);
        }

        return $weeks;
    }

    private static function trains(Session $session, MuscleGroup $muscle): bool
    {
        return array_any($session->slots, fn (ExerciseSlot $slot) => $slot->muscle === $muscle);
    }
}
