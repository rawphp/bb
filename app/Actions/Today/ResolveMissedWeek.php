<?php

namespace App\Actions\Today;

use App\Actions\Mesocycles\EndMesocycle;
use App\Capabilities\Today\Data\MissedWeekResolveInput;
use App\Domain\Plan\MissedWeeks;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\MissedWeeksPrompt;
use App\Support\Today\TodaySnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SCH-058: the answer to the missed-weeks prompt. Restart moves the mesocycle's start forward by whole plan weeks so
 * the week the user stopped in is this one, and moves the deload flags stored on its plan weeks as far (PLAN-022,
 * PLAN-023). It saves that resolution only: the day targets are left as they are. End is MESO-043 and MESO-044, which
 * {@see EndMesocycle} owns.
 */
final class ResolveMissedWeek
{
    public const NOT_DUE = 'That mesocycle isn\'t waiting for an answer: you have trained since, or it is no longer the current one.';

    public const NO_RESTART = 'The week you stopped in is this one already, so there is nothing to restart. End the mesocycle instead.';

    public function __construct(private readonly EndMesocycle $end) {}

    /** @return array{toast: string, dates: list<string>} the toast and the days (Y-m-d) whose Today and week strip change */
    public function __invoke(User $user, MissedWeekResolveInput $input): array
    {
        if ($input->choice === 'end') {
            ($this->end)($user, $input->mesocycle_id, $input->diet_break);

            return [
                'toast' => $input->diet_break ? 'Mesocycle ended. Next week is a diet break.' : 'Mesocycle ended',
                'dates' => $this->currentWeek(TodaySnapshot::load($user)),
            ];
        }

        return DB::transaction(function () use ($user, $input) {
            $snapshot = TodaySnapshot::load($user);
            $prompt = MissedWeeksPrompt::from($snapshot);
            if ($prompt === null || strcasecmp((string) $prompt->mesocycle->id, $input->mesocycle_id) !== 0) {
                throw new DomainException(self::NOT_DUE);
            }
            if (! $prompt->canRestart()) {
                throw new DomainException(self::NO_RESTART);
            }

            $prompt->mesocycle->update(['start_date' => $prompt->restartStart->format('Y-m-d')]);
            $moved = $this->moveDeloads($user, $snapshot, $prompt);

            return ['toast' => 'Week restarted', 'dates' => [...$this->currentWeek($snapshot), ...$moved]];
        });
    }

    /**
     * Moves the deload flags stored on plan weeks inside the mesocycle, each to the plan week its mesocycle week falls
     * on once restarted: a flagged deload with its diet break (PLAN-022), and a diet break turned off (PLAN-023), which
     * the mesocycle's own deload week stores without a deload flag. An away week's flags stay on its calendar week.
     * Each week touched ends with its own flags, less those leaving it, plus those arriving, all read before any write.
     *
     * @return list<string> the days (Y-m-d) of the plan weeks whose flags changed
     */
    private function moveDeloads(User $user, TodaySnapshot $snapshot, MissedWeeksPrompt $prompt): array
    {
        $train = $snapshot->train;
        $grid = $prompt->grid;
        $rows = $train->planWeeks->filter(fn (PlanWeek $w) => ($w->is_deload || $w->diet_break_off) && ! $w->is_away)->values();
        $indexes = $rows->map(fn (PlanWeek $w) => $grid->weekIndexContaining(TrainSnapshot::day($w->start_date)));
        $weeks = $indexes->filter(fn (?int $week) => $week !== null)->unique()->values()->all();
        $moves = MissedWeeks::movedDeloads($weeks, $prompt->index, $prompt->dated, $prompt->restartStart, $grid, $prompt->paused);

        $leaving = [];
        $arriving = [];
        foreach ($moves as $move) {
            $row = $rows->first(fn (PlanWeek $w, int $key) => $indexes[$key] === $move->from);
            if ($row === null) {
                continue;
            }
            $carried = [
                'deload' => (bool) $row->is_deload,
                'diet_break' => $row->is_deload && $row->is_diet_break,
                'diet_break_off' => (bool) $row->diet_break_off,
            ];
            $leaving[TrainSnapshot::day($row->start_date)->format('Y-m-d')] = $carried;
            $arriving[$grid->start($move->to)->format('Y-m-d')] = $carried;
        }

        $before = $train->planWeeks->mapWithKeys(fn (PlanWeek $w) => [TrainSnapshot::day($w->start_date)->format('Y-m-d') => $w]);
        $changed = [];
        foreach (array_unique([...array_keys($leaving), ...array_keys($arriving)]) as $day) {
            $row = $before[$day] ?? null;
            $flags = ['deload' => (bool) $row?->is_deload, 'diet_break' => (bool) $row?->is_diet_break, 'diet_break_off' => (bool) $row?->diet_break_off];
            $next = [];
            foreach ($flags as $flag => $own) {
                $next[$flag] = ($own && ! ($leaving[$day][$flag] ?? false)) || ($arriving[$day][$flag] ?? false);
            }
            if ($next === $flags) {
                continue;
            }
            // The user's own row for the week, else a new one: a row found is never moved to another owner.
            $week = $row ?? (new PlanWeek(['start_date' => $day, 'is_deload' => false, 'is_away' => false]))->forceFill(['user_id' => $user->id]);
            $week->is_deload = $next['deload'];
            $week->is_diet_break = $next['diet_break'];
            $week->diet_break_off = $next['diet_break_off'];
            $week->save();
            $changed[] = $day;
        }

        return $this->weekDays($changed, $snapshot);
    }

    /**
     * @param  list<string>  $starts  Y-m-d of plan weeks
     * @return list<string> every day of those plan weeks
     */
    private function weekDays(array $starts, TodaySnapshot $snapshot): array
    {
        $grid = $snapshot->train->grid();
        $days = [];
        foreach ($starts as $start) {
            $week = $grid?->weekIndexContaining(TrainSnapshot::day($start));
            foreach ($week === null ? [] : $grid->planWeek($week)->days() as $day) {
                $days[] = $day->format('Y-m-d');
            }
        }

        return $days;
    }

    /** @return list<string> the days (Y-m-d) of the plan week containing today */
    private function currentWeek(TodaySnapshot $snapshot): array
    {
        return $this->weekDays([$snapshot->today()->format('Y-m-d')], $snapshot);
    }
}
