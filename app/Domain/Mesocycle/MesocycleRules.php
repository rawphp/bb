<?php

namespace App\Domain\Mesocycle;

use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Session;
use App\Domain\Plan\DeloadCalories;
use App\Domain\Plan\PlanWeekGrid;
use App\Models\Mesocycle;
use DateTimeImmutable;

/**
 * The mesocycle rules the Train reads and the mesocycle writes share, beside {@see MesocycleCalendar}: when a new
 * mesocycle starts (MESO-004, MESO-060), whose priorities a copy takes (MESO-065), and the diet breaks (MESO-041,
 * MESO-044). Without a plan, `$grid` is null.
 */
final class MesocycleRules
{
    private const WEEKDAYS = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** MESO-004: today when it begins a plan week, otherwise the next plan week's first day. */
    public static function defaultStart(?PlanWeekGrid $grid, DateTimeImmutable $today): DateTimeImmutable
    {
        if ($grid === null) {
            return $today;
        }
        $index = $grid->weekIndexContaining($today);
        if ($index === null) {
            return $grid->start(0);
        }

        return $grid->isBoundary($today) ? $today : $grid->start($index + 1);
    }

    /** MESO-004: a start moves to the first day of its plan week. */
    public static function snapped(?PlanWeekGrid $grid, DateTimeImmutable $date): DateTimeImmutable
    {
        return $grid === null ? $date : $grid->start($grid->weekIndexContaining($date) ?? 0);
    }

    /**
     * MESO-060: the next mesocycle starts the plan week after the one ending (`$planned`), but never before the default
     * start (MESO-004), so the prompt of a mesocycle finished weeks ago does not point into the past.
     */
    public static function nextMesocycleStart(DateTimeImmutable $planned, DateTimeImmutable $defaultStart): DateTimeImmutable
    {
        return max($planned, $defaultStart);
    }

    /**
     * MESO-060: a copy's start when none is given: the next mesocycle's start when it copies the one ending (`$next`),
     * else the default start.
     */
    public static function copyStart(?DateTimeImmutable $next, DateTimeImmutable $defaultStart): DateTimeImmutable
    {
        return $next ?? $defaultStart;
    }

    /**
     * MESO-065: the phase type whose priority preset a copy takes, because its phase differs in type from the source's;
     * null when the old priorities carry over.
     */
    public static function priorityReset(?PhaseType $source, ?PhaseType $new): ?PhaseType
    {
        return $source !== null && $new !== null && $source !== $new ? $new : null;
    }

    /** MESO-044: ending a fat loss mesocycle early offers a diet break next week, unless this week is one. */
    public static function endOffersDietBreak(?PhaseType $phase, bool $currentWeekIsDietBreak): bool
    {
        return ($phase?->isFatLoss() ?? false) && ! $currentWeekIsDietBreak;
    }

    /**
     * MESO-041, PLAN-022: a week is a diet break when its plan week is flagged one, or it is a fat loss deload not
     * turned off (PLAN-023). `$phase` is the phase governing the week (PLAN-002).
     */
    public static function isDietBreakWeek(bool $flagged, ?PhaseType $phase, bool $isDeload, bool $turnedOff): bool
    {
        return $flagged || ($phase !== null && DeloadCalories::isDietBreak($phase, $isDeload, $turnedOff));
    }

    /**
     * MESO-005: every day has a weekday.
     *
     * @param  list<?int>  $weekdays  each day's ISO weekday, null for a floating day
     */
    public static function isWeekdaySplit(array $weekdays): bool
    {
        return $weekdays !== [] && ! in_array(null, $weekdays, true);
    }

    /**
     * MESO-045: the first training weekday no day uses, in plan-week order; null when all are used.
     *
     * @param  list<?int>  $weekdays  each day's ISO weekday
     * @param  list<int>  $trainingWeekdays  the profile's training days, ISO, in plan-week order
     */
    public static function freeWeekday(array $weekdays, array $trainingWeekdays): ?int
    {
        return array_find($trainingWeekdays, fn (int $weekday) => ! in_array($weekday, $weekdays, true));
    }

    /**
     * MESO-045 "Apply to all remaining weeks": a day added from week `$fromWeek` (0 is the first) on. SCH-039: the
     * weeks before it are stored as they ran, so they keep their days; the later weeks stored from it on run the new
     * day too, and the weeks past them run the whole rotation.
     *
     * @template TDay of array{id: string}
     *
     * @param  list<TDay>  $days  the rotation
     * @param  list<list<TDay>>  $earlierWeeks
     * @param  list<list<string>>  $laterWeeks  each later week's day ids
     * @param  TDay  $day
     * @return array{days: list<TDay>, earlier_weeks: list<list<TDay>>, later_weeks: list<list<string>>}
     */
    public static function addingDay(array $days, array $earlierWeeks, array $laterWeeks, int $fromWeek, array $day): array
    {
        $byId = array_column($days, null, 'id');
        for ($week = count($earlierWeeks); $week < $fromWeek; $week++) {
            $ids = $laterWeeks === [] ? array_keys($byId) : array_shift($laterWeeks);
            $earlierWeeks[] = array_values(array_filter(array_map(fn (string $id) => $byId[$id] ?? null, $ids)));
        }

        return [
            'days' => [...$days, $day],
            'earlier_weeks' => $earlierWeeks,
            'later_weeks' => array_map(fn (array $ids) => [...$ids, $day['id']], $laterWeeks),
        ];
    }

    /** MESO-005: an ISO weekday's name, which labels a day of a weekday split. */
    public static function weekdayName(int $weekday): string
    {
        return self::WEEKDAYS[$weekday] ?? 'Day';
    }

    /**
     * MESO-005: weekday names need one training day per session.
     *
     * @param  list<int>  $trainingWeekdays
     */
    public static function canUseWeekdays(int $sessionCount, array $trainingWeekdays): bool
    {
        return $sessionCount > 0 && count($trainingWeekdays) === $sessionCount;
    }

    /**
     * MESO-005: a build's days as the style labels them. A weekday split maps the days to the training weekdays in
     * order, and each reads as its weekday's name; when the counts differ, or the split floats, the days are "Day 1,
     * Day 2" with no weekday. Either way no label is stored ({@see Mesocycle::dayRows()}).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  'weekdays'|'floating'  $style
     * @param  list<int>  $trainingWeekdays  ISO, in plan-week order
     * @return list<array<string, mixed>>
     */
    public static function labelDays(array $days, string $style, array $trainingWeekdays): array
    {
        $weekdays = $style === 'weekdays' && self::canUseWeekdays(count($days), $trainingWeekdays);

        return array_map(
            fn (array $day, int $index) => array_replace($day, ['label' => null, 'weekday' => $weekdays ? $trainingWeekdays[$index] : null]),
            $days,
            array_keys($days),
        );
    }

    /**
     * MESO-034: an Off muscle's slots are left out of every session. A session left with none plans no sets; it keeps
     * its place, so the days keep their numbers.
     *
     * @param  list<Session>  $sessions
     * @param  list<MuscleGroup>  $off
     * @return list<Session>
     */
    public static function withoutOff(array $sessions, array $off): array
    {
        if ($off === []) {
            return $sessions;
        }

        return array_map(fn (Session $session) => new Session($session->id, $session->name, array_values(array_filter(
            $session->slots,
            fn (ExerciseSlot $slot) => ! in_array($slot->muscle, $off, true),
        ))), $sessions);
    }
}
