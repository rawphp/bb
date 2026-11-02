<?php

namespace App\Domain\Mesocycle;

use App\Domain\Model\ClosedRange;
use App\Domain\Model\PlanWeek;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use DateTimeImmutable;

/**
 * Where a saved mesocycle stands in time (MESO-042 to MESO-045, MESO-060, MESO-064, NAV-002). The Train reads and the
 * mesocycle writes both ask it, so a `can_*` flag and the write it offers cannot disagree.
 *
 * Its week n is the plan week n - 1 after the one holding its start (PLAN-008). Days are whole days in the zone of
 * the grid; `$today` is today's start. `$nextStart` is the start of the next saved mesocycle, if there is one.
 */
final readonly class MesocycleCalendar
{
    /** MESO-004: a mesocycle is at most 8 weeks long, the deload included. */
    public const MAX_WEEKS = 8;

    public function __construct(
        public DateTimeImmutable $start,
        public int $weeks,
        public ?DateTimeImmutable $endedOn,
        public PlanWeekGrid $grid,
        public DateTimeImmutable $today,
        public ?DateTimeImmutable $nextStart = null,
    ) {}

    /** Without a plan, the weeks run 7 days from the mesocycle's start. */
    public static function gridWithoutPlan(DateTimeImmutable $start): PlanWeekGrid
    {
        return new PlanWeekGrid($start, (int) $start->format('w') + 1);
    }

    /** From 1: week 1 before the start, the last week once it is over. */
    public function currentWeek(): int
    {
        $week = $this->grid->weekIndexCountingBack($this->today) - $this->startWeek() + 1;

        return max(1, min($week, $this->weeks));
    }

    /**
     * MESO-043: the last day of its last plan week, or the day before it was ended if that is sooner. It is before the
     * start when it was ended on its first day.
     */
    public function lastDay(): DateTimeImmutable
    {
        $lastWeek = $this->planWeek($this->weeks - 1);
        $last = $lastWeek->end() ?? $lastWeek->start;

        return $this->endedOn === null ? $last : min($last, $this->endedOn->modify('-1 day'));
    }

    public function hasStarted(): bool
    {
        return $this->today >= $this->start;
    }

    /** Its last day has not passed. */
    public function isRunning(): bool
    {
        return $this->today <= $this->lastDay();
    }

    /** MESO-043: it has started and still runs. Before its start, `ended_on` would come before `start_date`. */
    public function canEnd(): bool
    {
        return $this->hasStarted() && $this->isRunning();
    }

    /** MESO-042 leaves the deload week alone. */
    public function isInDeloadWeek(): bool
    {
        return $this->isRunning() && $this->currentWeek() === $this->weeks;
    }

    /**
     * MESO-042: from the week after the current one up to 8 (MESO-004), and no further than the plan week before the
     * next saved mesocycle starts.
     */
    public function lengthRange(): ClosedRange
    {
        $lower = $this->currentWeek() + 1;

        return new ClosedRange($lower, max($lower, min(self::MAX_WEEKS, $this->weeksBeforeNext() ?? self::MAX_WEEKS)));
    }

    /** MESO-042: the most weeks it can run before the next saved mesocycle starts; null when none is saved after it. */
    public function weeksBeforeNext(): ?int
    {
        return $this->nextStart === null ? null : $this->grid->weekIndexCountingBack($this->nextStart) - $this->startWeek();
    }

    /**
     * MESO-042: a running mesocycle out of its deload week. SCH-039: not while later weeks are stored, since they
     * fix the length.
     */
    public function canSetWeeks(int $weeks, bool $laterWeeksStored): bool
    {
        return $this->canChangeLength($laterWeeksStored) && $this->lengthRange()->contains($weeks);
    }

    public function canShorten(bool $laterWeeksStored): bool
    {
        return $this->canSetWeeks($this->weeks - 1, $laterWeeksStored);
    }

    public function canLengthen(bool $laterWeeksStored): bool
    {
        return $this->canSetWeeks($this->weeks + 1, $laterWeeksStored);
    }

    /** MESO-045: days can be added while it runs. */
    public function canAddDay(): bool
    {
        return $this->isRunning();
    }

    /** The plan week of a mesocycle week (0 is its first), with its true length. */
    public function planWeek(int $index): PlanWeek
    {
        $planIndex = $this->startWeek() + $index;
        if ($planIndex >= 0) {
            return $this->grid->planWeek($planIndex);
        }

        return new PlanWeek($this->weekStart($planIndex));
    }

    /** MESO-044: the first day of the plan week after today's. */
    public function nextPlanWeekStart(): DateTimeImmutable
    {
        return $this->weekStart($this->grid->weekIndexCountingBack($this->today) + 1);
    }

    /**
     * MESO-060: "Build your next mesocycle" is due from the first day of the deload week, or from the day it was
     * ended early. The deload follows the stored length, so a moved deload prompts in its new week.
     */
    public function isNextMesocycleDue(): bool
    {
        return $this->weekStart($this->deloadWeek()) <= $this->today
            || ($this->endedOn !== null && $this->endedOn <= $this->today);
    }

    /**
     * MESO-060: the next mesocycle starts the plan week after the one holding the last week, or the end.
     * MESO-064: an active rest adds the week off.
     */
    public function nextMesocycleStart(bool $activeRest): DateTimeImmutable
    {
        $lastWeek = $this->endedOn === null
            ? $this->deloadWeek()
            : min($this->grid->weekIndexCountingBack($this->endedOn), $this->deloadWeek());

        return $this->weekStart(max($lastWeek + 1, 0) + ($activeRest ? 1 : 0));
    }

    /**
     * MESO-005, SCH-040: the date of each day of a plan week. A day with a weekday (ISO) falls on it; the floating
     * days take, in turn, the training weekdays in plan-week order that no day with a weekday takes (MESO-045: a
     * "Day n" added to a full weekday split). A floating day with none left has no date.
     *
     * @param  list<?int>  $weekdays  each day's ISO weekday, null for a floating day
     * @param  list<int>  $trainingWeekdays  the profile's training days, ISO
     * @return list<?DateTimeImmutable>
     */
    public static function dayDates(PlanWeek $week, array $weekdays, array $trainingWeekdays, int $reviewIsoWeekday): array
    {
        $byWeekday = [];
        foreach ($week->days() as $date) {
            $byWeekday[(int) $date->format('N')] ??= $date;
        }
        $taken = array_filter($weekdays, fn (?int $weekday) => $weekday !== null);
        $floating = array_values(array_diff(
            PlanWeekDays::inPlanWeekOrder(array_values(array_unique($trainingWeekdays)), $reviewIsoWeekday),
            $taken,
        ));

        $dates = [];
        $next = 0;
        foreach ($weekdays as $weekday) {
            $weekday ??= $floating[$next++] ?? null;
            $dates[] = $weekday === null ? null : $byWeekday[$weekday] ?? null;
        }

        return $dates;
    }

    private function canChangeLength(bool $laterWeeksStored): bool
    {
        return $this->isRunning() && ! $this->isInDeloadWeek() && ! $laterWeeksStored;
    }

    private function startWeek(): int
    {
        return $this->grid->weekIndexCountingBack($this->start);
    }

    private function deloadWeek(): int
    {
        return $this->startWeek() + $this->weeks - 1;
    }

    /** A plan week's first day; before the plan, whole weeks back from its start. */
    private function weekStart(int $planIndex): DateTimeImmutable
    {
        if ($planIndex >= 0) {
            return $this->grid->start($planIndex);
        }

        return $this->grid->start(0)->modify(sprintf('%+d days', 7 * $planIndex));
    }
}
