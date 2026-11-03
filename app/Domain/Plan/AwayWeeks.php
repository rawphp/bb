<?php

namespace App\Domain\Plan;

use App\Domain\Model\Phase;
use App\Domain\Model\Plan;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use DateTimeImmutable;

/**
 * SCH-060 and the away-week part of PLAN-025 (rp-clone TodayModel+Away and AwayWeekModel): which plan weeks can be
 * marked away, when a week can be the deload, and what an away week does to the diet breaks and to the phase's end.
 * One answer for the Away sheet, for its save and for the week strip's menu.
 */
final class AwayWeeks
{
    /** SCH-060: how many weeks ahead a phase with no end date allows. */
    public const OPEN_ENDED_WEEKS = 12;

    /** SCH-060: the training weeks a deload needs before it in its own mesocycle. */
    public const MINIMUM_TRAINING_WEEKS = 3;

    private ?int $phaseIndex = null;

    /** @var list<int> */
    private array $offered = [];

    /**
     * @param  DateTimeImmutable  $today  a day of the plan week in progress
     * @param  list<DatedMesocycle>  $mesocycles  the saved ones
     * @param  array<int, AwayWeekRow>  $rows  what each stored plan week keeps, by plan week
     */
    public function __construct(
        private readonly Plan $plan,
        private readonly PlanWeekGrid $grid,
        DateTimeImmutable $today,
        private readonly array $mesocycles,
        private readonly array $rows,
    ) {
        $current = $grid->weekIndexContaining($today);
        $index = $current === null ? null : PlanRules::phaseIndexCoveringWeek($current, $plan, $grid);
        $range = $index === null ? null : PlanRules::weeks($plan->phases[$index], $grid);
        if ($current === null || $index === null || $range === null) {
            return;
        }
        $this->phaseIndex = $index;
        $last = $plan->phases[$index]->end === null ? $current + self::OPEN_ENDED_WEEKS : $range->upper - 1;
        $this->offered = $last > $current ? range($current + 1, $last) : [];
    }

    /** The phase of the week in progress; none before the plan or when no phase covers that week. */
    public function phase(): ?Phase
    {
        return $this->phaseIndex === null ? null : $this->plan->phases[$this->phaseIndex];
    }

    /** @return list<int> the plan weeks that can be marked, in order */
    public function offered(): array
    {
        return $this->offered;
    }

    public function isOffered(int $week): bool
    {
        return in_array($week, $this->offered, true);
    }

    public function start(int $week): DateTimeImmutable
    {
        return $this->grid->start($week);
    }

    /** The plan week starting on a day; null when the day does not start one. */
    public function weekStartingOn(DateTimeImmutable $day): ?int
    {
        return $this->grid->isBoundary($day) ? $this->grid->weekIndexContaining($day) : null;
    }

    /** @return list<DateTimeImmutable> */
    public function days(int $week): array
    {
        return $this->grid->planWeek($week)->days();
    }

    public function row(int $week): AwayWeekRow
    {
        return $this->rows[$week] ?? new AwayWeekRow;
    }

    // MARK: the deload

    /**
     * SCH-060: a deload needs at least 3 training weeks before the away week in its own mesocycle. Paused and away
     * weeks do not count, and every marked week must qualify (as a deload, the other marked weeks are away too).
     *
     * @param  list<int>  $weeks  the weeks marked
     */
    public function deloadOffered(array $weeks): bool
    {
        $paused = [];
        $awayDeloads = $weeks;
        foreach ($this->rows as $week => $row) {
            if ($row->isAway && $row->training === Training::Pause && ! in_array($week, $weeks, true)) {
                $paused[] = $week;
            }
            if ($row->isAway && $row->training === Training::Deload) {
                $awayDeloads[] = $week;
            }
        }
        $awayDeloads = array_unique($awayDeloads);
        foreach ($weeks as $week) {
            $covering = PlanRules::coveringMesocycle($week, $this->mesocycles, $this->grid, $paused);
            if ($covering === null) {
                return false;
            }
            $first = $covering->placed->startWeek;
            $counted = PlanRules::mesocycleWeek($week, $first, $paused);
            $away = count(array_filter($awayDeloads, fn (int $other) => $other >= $first && $other < $week));
            if ($counted - $away < self::MINIMUM_TRAINING_WEEKS) {
                return false;
            }
        }

        return $weeks !== [];
    }

    // MARK: PLAN-025 the diet breaks

    /** SCH-060, PLAN-025: an away week at maintenance in a Fat loss phase counts as a diet break. */
    public function isDietBreakChoice(bool $away, Calories $calories): bool
    {
        return $away && ($this->phase()?->type->isFatLoss() ?? false) && $calories === Calories::Maintenance;
    }

    /** What the week's diet break is once saved: its choice, or the diet break it already was some other way (MESO-044). */
    public function storedDietBreak(int $week, bool $away, Calories $calories): bool
    {
        $row = $this->row($week);

        return $this->isDietBreakChoice($away, $calories) || ($row->isDietBreak && ! self::isMovable($row));
    }

    /** PLAN-025: the diet breaks a week gains (+1) or loses (-1) by this choice, each a week of the phase. */
    public function change(int $week, bool $away, Calories $calories): int
    {
        $row = $this->row($week);
        $choice = $this->isDietBreakChoice($away, $calories);
        if ($choice && ! $row->isDietBreak) {
            return 1;
        }

        return ! $choice && self::isMovable($row) ? -1 : 0;
    }

    /**
     * PLAN-025: what saving a week moves the phase end by, in weeks, and the keep_phase_end to store for it.
     *
     * The end is always moved by exactly the away diet breaks that moved it. A break this sheet made either moved the
     * end (stored keep_phase_end false) or did not (true), and that flag is the record. `$keep` decides only whether a
     * break moves the end out: a break that moved it gives the week back as soon as it stops being a break, whatever
     * `$keep` says, as no break would be left to hold that week. A week away without a diet break stores `$keep` as the
     * user's choice; a week not away stores false.
     *
     * @return array{0: int, 1: bool}
     */
    public function endMove(int $week, bool $away, Calories $calories, bool $keep): array
    {
        $row = $this->row($week);
        $movedBefore = self::isMovable($row) && ! $row->keepPhaseEnd;
        $counts = $this->isDietBreakChoice($away, $calories) && (! $row->isDietBreak || self::isMovable($row));
        if (! $counts) {
            return [$movedBefore ? -1 : 0, $away && $keep];
        }
        $movedAfter = $movedBefore || ! $keep;

        return [(int) $movedAfter - (int) $movedBefore, ! $movedAfter];
    }

    /** A diet break this sheet made: away with maintenance. Only these move or undo the end date. */
    private static function isMovable(AwayWeekRow $row): bool
    {
        return $row->isDietBreak && $row->isAway && $row->calories === Calories::Maintenance;
    }

    // MARK: PLAN-025 the end date

    /**
     * The phase's end, and every later phase's start and end, moved a week for each diet break gained or lost (SAFE-017).
     * A phase with no end, or a net of none, moves nothing.
     *
     * @return list<PhaseWrite>
     */
    public function movedPhases(int $net): array
    {
        $phase = $this->phase();
        if ($phase === null || $phase->end === null || $net === 0) {
            return [];
        }
        $by = fn (DateTimeImmutable $day): DateTimeImmutable => $day->modify(sprintf('%+d days', 7 * $net));
        $moved = [new PhaseWrite($phase->withDates($phase->start, $by($phase->end)), true)];
        foreach (array_slice($this->plan->phases, $this->phaseIndex + 1) as $later) {
            $moved[] = new PhaseWrite($later->withDates($by($later->start), $later->end === null ? null : $by($later->end)), true);
        }

        return $moved;
    }
}
