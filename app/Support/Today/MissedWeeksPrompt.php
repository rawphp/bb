<?php

namespace App\Support\Today;

use App\Domain\Plan\DatedMesocycle;
use App\Domain\Plan\MissedWeeks;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use DateTimeImmutable;

/**
 * SCH-058: the question Today asks after a plan week or more without training: restart the week the user stopped in,
 * or end the mesocycle and build a new one. Always about today, whatever date Today shows. While it is due, SCH-057's
 * missed-day prompt waits.
 *
 * The weeks are read from what Today places, so a paused away week, which places no sessions, never counts.
 */
final readonly class MissedWeeksPrompt
{
    /**
     * @param  int  $index  the mesocycle's place among the saved ones
     * @param  int  $stoppedWeek  the mesocycle week (from 1) the user stopped in
     * @param  int  $shift  whole plan weeks a restart moves the start
     * @param  list<?DatedMesocycle>  $dated  every saved mesocycle, as the plan places them
     * @param  list<int>  $paused  the plan weeks an away week pauses
     */
    private function __construct(
        public Mesocycle $mesocycle,
        public int $index,
        public int $stoppedWeek,
        public int $shift,
        public DateTimeImmutable $restartStart,
        public array $dated,
        public array $paused,
        public PlanWeekGrid $grid,
        public bool $offersDietBreak,
    ) {}

    /**
     * Due when, since the plan week of the last started session, a whole plan week has ended in which the current
     * mesocycle placed sessions and none was started. The current mesocycle is the latest saved one that has started,
     * is not ended and has none saved after it.
     */
    public static function from(TodaySnapshot $snapshot): ?self
    {
        $train = $snapshot->train;
        $grid = $train->grid();
        $today = $train->today;
        $current = $grid?->weekIndexContaining($today);
        $mesocycle = $train->current();
        if ($grid === null || $current === null || $mesocycle === null || $mesocycle->ended_on !== null) {
            return null;
        }
        $start = TrainSnapshot::day($mesocycle->start_date);
        if ($train->saved()->contains(fn (Mesocycle $m) => TrainSnapshot::day($m->start_date) > $start)) {
            return null;
        }
        $saved = $train->saved()->values();
        $dated = $saved->map(fn (Mesocycle $m) => new DatedMesocycle(TrainSnapshot::day($m->start_date), (int) $m->weeks))->all();
        $paused = self::pausedWeeks($train, $grid);
        $index = $saved->search(fn (Mesocycle $m) => $m->is($mesocycle));
        $placement = $index === false ? null : PlanRules::placements($dated, $grid, $paused)[$index];
        if ($placement === null) {
            return null;
        }

        $lastStarted = $train->sessions
            ->filter(fn (WorkoutSession $s) => $s->mesocycle_id === $mesocycle->id && $s->actual_start_at !== null)
            ->map(fn (WorkoutSession $s) => TodaySnapshot::instant($s->actual_start_at))
            ->max();
        $lastStartedWeek = $lastStarted === null ? null : $grid->weekIndexCountingBack(TrainSnapshot::day($lastStarted));

        // Only weeks from the last started session's on are read: a week after it makes the prompt due, and the user
        // stopped in it or later. Weeks past the mesocycle's own place hold none of its sessions.
        $weeks = [];
        $stopped = null;
        for ($week = max(0, $placement->startWeek, $lastStartedWeek ?? 0); $week <= min($current, $placement->nextStartWeek() - 1); $week++) {
            $placed = [];
            foreach ($grid->planWeek($week)->days() as $day) {
                $found = $snapshot->workout($day);
                if ($found !== null && $found->mesocycle->id === $mesocycle->id) {
                    $placed[] = $found;
                }
            }
            $weeks[] = new MissedWeeks\Week(
                $week,
                count($placed),
                count(array_filter($placed, fn (TodayWorkoutItem $w) => in_array($w->state, [TodayWorkoutItem::STARTED, TodayWorkoutItem::DONE], true))),
            );
            $stopped ??= array_find($placed, fn (TodayWorkoutItem $w) => $w->state === TodayWorkoutItem::PLANNED);
        }
        if (! MissedWeeks::isDue($weeks, $lastStartedWeek, $current)) {
            return null;
        }
        $inWeek = $snapshot->planning($today)['plan']->mesocycleWeek ?? null;
        $stoppedWeek = $stopped->week ?? ($inWeek === null ? null : $inWeek + 1);
        if ($stoppedWeek === null) {
            return null;
        }
        $shift = MissedWeeks::restartShift($stoppedWeek - 1, $index, $dated, $current, $grid, $paused);

        return new self(
            $mesocycle, $index, $stoppedWeek, $shift, $start->modify(sprintf('+%d days', 7 * $shift)), $dated, $paused, $grid,
            $train->endOffersDietBreak($mesocycle),
        );
    }

    /** Restart is offered only when it moves the start. */
    public function canRestart(): bool
    {
        return $this->shift > 0;
    }

    public function title(): string
    {
        return 'Welcome back';
    }

    public function text(): string
    {
        $name = $this->mesocycle->name ?? 'Mesocycle';
        $since = $this->stoppedWeek === 1 ? "You haven't trained in {$name} yet." : sprintf("You haven't trained since week %d of %s.", $this->stoppedWeek - 1, $name);

        return $this->canRestart()
            ? "{$since} Restart week {$this->stoppedWeek}, or end this mesocycle and build a new one."
            : "{$since} End this mesocycle and build a new one.";
    }

    public function endWarning(): string
    {
        return 'Ending a mesocycle keeps its logged workouts but drops the weeks still to come.';
    }

    /**
     * SCH-060: the plan weeks an away week pauses.
     *
     * @return list<int>
     */
    private static function pausedWeeks(TrainSnapshot $train, PlanWeekGrid $grid): array
    {
        return $train->planWeeks
            ->filter(fn (PlanWeek $w) => $w->is_away && $w->away_training === Training::Pause->value)
            ->map(fn (PlanWeek $w) => $grid->weekIndexContaining(TrainSnapshot::day($w->start_date)))
            ->filter(fn (?int $week) => $week !== null)
            ->values()->all();
    }
}
