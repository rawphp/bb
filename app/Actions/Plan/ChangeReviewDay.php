<?php

namespace App\Actions\Plan;

use App\Actions\Settings\SaveProfile;
use App\Domain\Model\Plan as DomainPlan;
use App\Domain\Model\ReviewDayChange;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * PLAN-008: a review day other than the one chosen last changes from the next plan-week boundary. The transition week
 * is shortened or lengthened to fit, and the phases, mesocycles and plan weeks after its first day keep their week
 * indices on the new grid, which keeps them on one grid (PLAN-002). The change is a row in `plan_review_day_changes`;
 * a row on or before today is history and never changes. Before the plan starts there is no boundary: the starting
 * day itself changes, and the targets rebuild, as week 0's length changed (ONB-016). Nothing is written for the day
 * already chosen.
 *
 * Runs inside {@see SaveProfile}'s transaction, which has the plan and profile rows locked.
 */
final class ChangeReviewDay
{
    /**
     * @param  int  $isoWeekday  1 = Monday ... 7 = Sunday
     * @return bool true when the plan had not started and the day changed, so the targets must rebuild
     */
    public function __invoke(User $user, int $isoWeekday): bool
    {
        // The plan row is already locked by SaveProfile; a locking read sees the plan as it is now.
        $snapshot = PlanSnapshot::load($user, lock: true);
        if ($snapshot === null) {
            return false;
        }
        $domain = $snapshot->domain;
        if ($isoWeekday === PlanWeekDays::isoWeekdayFromCalendar($domain->currentReviewWeekday())) {
            return false;
        }

        $today = $snapshot->today;
        $result = PlanRules::changingReviewDay($domain, PlanWeekDays::calendarWeekdayFromIso($isoWeekday), $today);

        // Changing the day and changing it back leaves no change: one that repeats the weekday in force is dropped,
        // which does not alter the grid.
        $kept = [];
        $inForce = $result->reviewWeekday;
        foreach ($result->reviewDayChanges as $change) {
            if ($change->weekday !== $inForce) {
                $kept[] = $change;
            }
            $inForce = $change->weekday;
        }

        $old = $snapshot->grid;
        $new = new PlanWeekGrid($result->start, $result->reviewWeekday, $kept);
        $started = $snapshot->todayWeek !== null;
        $boundary = $started ? $old->start($snapshot->todayWeek + 1) : $domain->start;

        $plan = $snapshot->plan;
        $plan->review_weekday = PlanWeekDays::isoWeekdayFromCalendar($result->reviewWeekday);
        $plan->save();

        self::writeChanges($plan, $kept, $today);
        self::movePhases($snapshot, $result);
        self::moveRows(Mesocycle::query()->where('user_id', $user->id), $old, $new, $boundary);
        self::moveRows(PlanWeek::query()->where('user_id', $user->id), $old, $new, $boundary);

        return ! $started;
    }

    /**
     * Pending rows not in the result go, then the result's new ones come in. A row on or before today stays as it is.
     *
     * @param  list<ReviewDayChange>  $kept
     */
    private static function writeChanges(Plan $plan, array $kept, DateTimeImmutable $today): void
    {
        $wanted = [];
        foreach ($kept as $change) {
            $wanted[$change->from->format('Y-m-d')] = PlanWeekDays::isoWeekdayFromCalendar($change->weekday);
        }

        $stored = [];
        foreach ($plan->reviewDayChanges as $row) {
            $date = PlanSnapshot::day($row->effective_on)->format('Y-m-d');
            if ($date > $today->format('Y-m-d') && ($wanted[$date] ?? null) !== $row->review_weekday) {
                $row->delete();

                continue;
            }
            $stored[$date] = true;
        }
        foreach ($wanted as $date => $weekday) {
            if (! isset($stored[$date])) {
                $plan->reviewDayChanges()->create(['effective_on' => $date, 'review_weekday' => $weekday]);
            }
        }
        $plan->unsetRelation('reviewDayChanges');
    }

    /** Each phase whose dates moved keeps its weeks and takes the new dates. */
    private static function movePhases(PlanSnapshot $snapshot, DomainPlan $result): void
    {
        $dates = fn (DateTimeInterface|string|null $date) => $date === null ? null : PlanSnapshot::day($date)->format('Y-m-d');
        foreach ($result->phases as $moved) {
            $phase = $snapshot->phases->first(fn (Phase $phase) => (string) $phase->id === $moved->id);
            if ($phase !== null && ($dates($moved->start) !== $dates($phase->start_date) || $dates($moved->end) !== $dates($phase->end_date))) {
                $phase->forceFill(['start_date' => $dates($moved->start), 'end_date' => $dates($moved->end)])->save();
            }
        }
    }

    /**
     * Rows starting after the boundary move to the new start of the same week index.
     *
     * @param  Builder<Mesocycle>|Builder<PlanWeek>  $query  the user's live rows
     */
    private static function moveRows(Builder $query, PlanWeekGrid $old, PlanWeekGrid $new, DateTimeImmutable $boundary): void
    {
        foreach ($query->whereNotNull('start_date')->whereDate('start_date', '>', $boundary->format('Y-m-d'))->get() as $row) {
            $index = $old->weekIndexContaining(PlanSnapshot::day($row->start_date));
            $start = $index === null ? null : $new->start($index)->format('Y-m-d');
            if ($start !== null && $start !== PlanSnapshot::day($row->start_date)->format('Y-m-d')) {
                $row->forceFill(['start_date' => $start])->save();
            }
        }
    }
}
