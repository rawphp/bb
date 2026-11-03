<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\AwayWeekSetInput;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\AwayOffer;
use App\Support\Today\ChangedDays;
use App\Support\Today\TodaySnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SCH-060, PLAN-025: mark plan weeks away, change them, or mark them not away (rp-clone AwayWeekModel.save). A week
 * that gains or loses a diet break moves the phase's end date, and the phases after it, by a week, unless that week
 * keeps the end date. The weeks are checked against what the Away sheet offers before anything is saved. The days
 * returned run from the earliest week saved through the last day the client can show (SCH-023): a paused or
 * un-paused week changes every later week's sessions, deload and menu.
 */
final class SetAwayWeeks
{
    /** @return array{dates: list<string>, offered: list<string>, toast: string} the days changed, the weeks the sheet offers now or offered before, and what to say */
    public function __invoke(User $user, AwayWeekSetInput $input): array
    {
        // The plan is read and checked under its row lock, so two saves to one plan run one after the other.
        return DB::transaction(fn (): array => $this->set($user, $input));
    }

    /** @return array{dates: list<string>, offered: list<string>, toast: string} */
    private function set(User $user, AwayWeekSetInput $input): array
    {
        $plan = PlanSnapshot::load($user, lock: true) ?? throw new DomainException('There is no plan yet.');
        $away = AwayOffer::fromSnapshot($plan);

        $weeks = [];
        foreach ($input->week_starts as $start) {
            $week = $away->weekStartingOn(PlanSnapshot::day($start));
            if ($week === null || ! $away->isOffered($week)) {
                throw new DomainException('Only the weeks the Away sheet offers can be marked away or cleared.');
            }
            $weeks[] = $week;
        }
        $weeks = array_values(array_unique($weeks));
        sort($weeks);
        if ($weeks === []) {
            throw new DomainException('Pick at least one week.');
        }
        $training = Training::from($input->training);
        $calories = AwayOffer::calories($input->calories);
        if ($input->away && $training === Training::Deload && ! $away->deloadOffered($weeks)) {
            throw new DomainException('A deload needs 3 training weeks of its mesocycle before every week marked.');
        }

        $net = 0;
        $dates = [];
        $updated = false;
        foreach ($weeks as $week) {
            $before = $away->row($week);
            $row = $plan->weekRow($week);
            $start = $away->start($week)->format('Y-m-d');
            if ($row === null) {
                $row = new PlanWeek(['start_date' => $start, 'is_deload' => false, 'is_diet_break' => false, 'is_away' => false]);
                $row->user_id = $user->id;
            }
            $updated = $updated || $before->isAway;

            // PLAN-025: a week sent without keep_phase_end keeps what it was saved with.
            [$move, $storedKeep] = $away->endMove($week, $input->away, $calories, $input->keep_phase_end ?? $before->keepPhaseEnd);
            $net += $move;
            $row->fill([
                'is_away' => $input->away,
                'away_training' => $input->away ? $training->value : null,
                'away_calories' => $input->away ? $calories->value : null,
                'is_diet_break' => $away->storedDietBreak($week, $input->away, $calories),
                'keep_phase_end' => $storedKeep,
            ])->save();

            array_push($dates, ...array_map(fn (\DateTimeImmutable $day) => $day->format('Y-m-d'), $away->days($week)));
        }

        foreach ($away->movedPhases($net) as $write) {
            $plan->findPhase($write->phase->id)?->update([
                'start_date' => $write->phase->start->format('Y-m-d'),
                'end_date' => $write->phase->end?->format('Y-m-d'),
            ]);
        }

        // The weeks the sheet offered, and those it offers once the phase has moved.
        $after = AwayOffer::fromSnapshot(PlanSnapshot::load($user) ?? $plan);
        $offered = array_values(array_unique([...$away->offered(), ...$after->offered()]));
        sort($offered);

        // A paused or un-paused week moves the sessions, deload and menu of every later week.
        $dates = array_values(array_unique([...ChangedDays::from(TodaySnapshot::loadWeek($user), $away->start($weeks[0])), ...$dates]));

        return [
            'dates' => $dates,
            'offered' => array_map(fn (int $week) => $after->start($week)->format('Y-m-d'), $offered),
            'toast' => match (true) {
                ! $input->away => count($weeks) === 1 ? 'Week no longer away' : 'Weeks no longer away',
                $updated && count($weeks) === 1 => 'Away week updated',
                default => count($weeks) === 1 ? 'Week marked away' : 'Weeks marked away',
            },
        ];
    }
}
