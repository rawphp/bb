<?php

namespace App\Actions\Today;

use App\Actions\Mesocycles\EndMesocycle;
use App\Capabilities\Today\Data\MissedWeekResolveInput;
use App\Domain\Plan\MissedWeeks;
use App\Domain\Plan\MissedWeeks\FlagMove;
use App\Domain\Plan\MissedWeeks\Flags;
use App\Models\Mesocycle;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\ChangedDays;
use App\Support\Today\MissedWeeksPrompt;
use App\Support\Today\TodaySnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SCH-058: the answer to the missed-weeks prompt. Restart moves the mesocycle's start forward by whole plan weeks so
 * the week the user stopped in is this one, and moves the deload flags stored on its plan weeks as far (PLAN-022,
 * PLAN-023). It saves that resolution only: the day targets are left as they are. End is MESO-043 and MESO-044, which
 * {@see EndMesocycle} owns. Either answer is accepted only for the mesocycle the prompt is due for. Both change the
 * sessions, deload and menu of every later week, so the days returned run from this plan week (a restart: from the plan
 * week of the old start) through the last day of the strip week the client can show (SCH-023).
 */
final class ResolveMissedWeek
{
    public const NOT_DUE = 'That mesocycle isn\'t waiting for an answer: you have trained since, or it is no longer the current one.';

    public const NO_RESTART = 'The week you stopped in is this one already, so there is nothing to restart. End the mesocycle instead.';

    public function __construct(private readonly EndMesocycle $end) {}

    /** @return array{toast: string, dates: list<string>} the toast and the days (Y-m-d) whose Today and week strip change */
    public function __invoke(User $user, MissedWeekResolveInput $input): array
    {
        return DB::transaction(function () use ($user, $input) {
            // The plan row is locked first, as the other writes to the plan's weeks do, so two answers (or an answer and
            // an away save) run one after the other and each reads what the other wrote.
            Plan::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $snapshot = TodaySnapshot::loadWeek($user);
            $prompt = MissedWeeksPrompt::from($snapshot);
            if ($prompt === null || strcasecmp((string) $prompt->mesocycle->id, $input->mesocycle_id) !== 0) {
                throw new DomainException(self::NOT_DUE);
            }

            if ($input->choice === 'end') {
                ($this->end)($user, $input->mesocycle_id, $input->diet_break);

                return [
                    'toast' => $input->diet_break ? 'Mesocycle ended. Next week is a diet break.' : 'Mesocycle ended',
                    'dates' => ChangedDays::fromCurrentWeek(TodaySnapshot::loadWeek($user)),
                ];
            }
            if (! $prompt->canRestart()) {
                throw new DomainException(self::NO_RESTART);
            }

            $oldStart = TrainSnapshot::day($prompt->mesocycle->start_date);
            $prompt->mesocycle->update(['start_date' => $prompt->restartStart->format('Y-m-d')]);
            $moved = $this->moveDeloads($user, $snapshot, $prompt);

            // The sessions leave every day from the old start (none show before the start), and every later week's
            // sessions and deload move with it: the plan and its last day do not, so the snapshot read for the write serves.
            return [
                'toast' => 'Week restarted',
                'dates' => array_values(array_unique([...ChangedDays::fromCurrentWeek($snapshot, $oldStart), ...$moved])),
            ];
        });
    }

    /**
     * Moves the deload flags stored on plan weeks inside the mesocycle, each to the plan week its mesocycle week falls
     * on once restarted: a flagged deload with its diet break (PLAN-022), and a diet break turned off (PLAN-023), which
     * the mesocycle's own deload week stores without a deload flag. An away week's flags stay on its calendar week.
     * What each week ends with is {@see MissedWeeks::flagsAfterMoves()}'s; this loads the rows and saves them.
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

        $before = $train->planWeeks->mapWithKeys(fn (PlanWeek $w) => [TrainSnapshot::day($w->start_date)->format('Y-m-d') => $w]);
        $flags = $before->map(fn (PlanWeek $w) => new Flags((bool) $w->is_deload, (bool) $w->is_diet_break, (bool) $w->diet_break_off))->all();
        $flagMoves = [];
        foreach ($moves as $move) {
            $row = $rows->first(fn (PlanWeek $w, int $key) => $indexes[$key] === $move->from);
            if ($row !== null) {
                $flagMoves[] = new FlagMove(TrainSnapshot::day($row->start_date)->format('Y-m-d'), $grid->start($move->to)->format('Y-m-d'));
            }
        }

        $changed = [];
        foreach (MissedWeeks::flagsAfterMoves($flags, $flagMoves) as $day => $next) {
            // The user's own row for the week, else a new one: a row found is never moved to another owner.
            $week = $before[$day] ?? (new PlanWeek(['start_date' => $day, 'is_deload' => false, 'is_away' => false]))->forceFill(['user_id' => $user->id]);
            $week->is_deload = $next->deload;
            $week->is_diet_break = $next->dietBreak;
            $week->diet_break_off = $next->dietBreakOff;
            $week->save();
            $changed[] = (string) $day;
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
}
