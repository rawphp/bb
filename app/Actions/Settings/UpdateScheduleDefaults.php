<?php

namespace App\Actions\Settings;

use App\Capabilities\Settings\Data\BusyPeriodInput;
use App\Capabilities\Settings\Data\ScheduleDefaultsUpdateInput;
use App\Capabilities\Settings\Data\StepRangeInput;
use App\Capabilities\Settings\Data\TrainingDayInput;
use App\Capabilities\Settings\Data\WeekdayInput;
use App\Capabilities\Support\FieldRefused;
use App\Domain\Model\Plan as DomainPlan;
use App\Domain\Model\ReviewDayChange;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * SET-005: the Schedule defaults form saves the eating window, meal count, training days, busy periods and step plan
 * on the profile, then rebuilds the targets (SET-004, {@see SaveProfile}). Weigh-in days are not on this form.
 *
 * SCH-039: while a mesocycle covers next plan week, a different set of training weekdays would leave its sessions on
 * the old days, so the write is refused and `training_weekdays_change` moves them instead. New times or minutes on the
 * same weekdays save as usual.
 *
 * PLAN-008: a review day other than the one chosen last changes from the next plan-week boundary. The transition week
 * is shortened or lengthened to fit, and the phases, mesocycles and plan weeks after it keep their week indices on the
 * new grid, which keeps them on one grid (PLAN-002). The change is a row in `plan_review_day_changes`; a row on or before
 * today is history and never changes. Before the plan starts there is no boundary: the starting day itself changes, and
 * the targets rebuild, as week 0's length changed (ONB-016). Null leaves the day as it is.
 */
final class UpdateScheduleDefaults
{
    public const MESOCYCLE_UNDER_WAY = 'A mesocycle is under way. Change training weekdays to move its sessions from next week.';

    public const NO_PLAN_REVIEW_DAY = 'There is no plan yet, so there is no review day to set.';

    public const ONE_SESSION_A_DAY = 'Each weekday can have one training session.';

    public const ONE_RANGE_A_DAY = 'Each weekday can have one step range.';

    public function __construct(private readonly SaveProfile $save) {}

    /** @return list<string> the dates the rebuild wrote, Y-m-d */
    public function __invoke(User $user, ScheduleDefaultsUpdateInput $input): array
    {
        $trainingDays = self::byWeekday($input->training_days, 'training_days', self::ONE_SESSION_A_DAY);
        $stepPlan = self::byWeekday($input->step_plan, 'step_plan', self::ONE_RANGE_A_DAY);

        return ($this->save)($user, [
            'eating_window' => ['start' => $input->eating_window_start, 'end' => $input->eating_window_end],
            'meal_count' => $input->meal_count,
            'training_days' => array_map(fn (TrainingDayInput $day) => [
                'weekday' => $day->weekday, 'start' => $day->start, 'minutes' => $day->minutes,
            ], $trainingDays),
            'busy_periods' => array_map(fn (BusyPeriodInput $period) => [
                'label' => $period->label,
                'start' => $period->start,
                'end' => $period->end,
                'weekdays' => self::weekdays($period->weekdays),
            ], $input->busy_periods),
            'step_plan' => array_map(fn (StepRangeInput $range) => [
                'weekday' => $range->weekday, 'min' => $range->min, 'max' => $range->max,
            ], $stepPlan),
        ], fn (Profile $profile) => self::check($user, $profile, $input));
    }

    /**
     * Runs under the profile's lock, before anything saves.
     *
     * @return bool true when the review day moved before the plan started, so the targets must rebuild
     */
    private static function check(User $user, Profile $profile, ScheduleDefaultsUpdateInput $input): bool
    {
        // The plan row is already locked by SaveProfile; a locking read sees the plan as it is now.
        $plan = PlanSnapshot::load($user, lock: true);

        if ($input->review_weekday !== null) {
            if ($plan === null) {
                throw new FieldRefused('review_weekday', self::NO_PLAN_REVIEW_DAY);
            }
        }

        $stored = array_map(fn (array $day): int => (int) $day['weekday'], TodaySnapshot::json($profile->training_days));
        $sent = array_map(fn (TrainingDayInput $day): int => $day->weekday, $input->training_days);
        sort($stored);
        sort($sent);
        if ($stored !== array_values(array_unique($sent)) && $plan?->mesocycleCoveringWeek($plan->nextWeek()) !== null) {
            throw new FieldRefused('training_days', self::MESOCYCLE_UNDER_WAY);
        }

        return $plan !== null && $input->review_weekday !== null && self::changeReviewDay($user, $plan, $input->review_weekday);
    }

    /**
     * PLAN-008: records a review day other than the one chosen last, and moves what sits after the transition week's
     * first day to the same week indices of the new grid. Nothing is written for the day already chosen.
     *
     * @param  int  $iso  1 = Monday ... 7 = Sunday
     * @return bool true when the plan had not started
     */
    private static function changeReviewDay(User $user, PlanSnapshot $snapshot, int $iso): bool
    {
        $domain = $snapshot->domain;
        if ($iso === PlanWeekDays::isoWeekdayFromCalendar($domain->currentReviewWeekday())) {
            return false;
        }

        $today = $snapshot->today;
        $result = PlanRules::changingReviewDay($domain, PlanWeekDays::calendarWeekdayFromIso($iso), $today);

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
            $date = $row->effective_on->format('Y-m-d');
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
        $dates = fn (?DateTimeImmutable $date) => $date?->format('Y-m-d');
        foreach ($result->phases as $moved) {
            $phase = $snapshot->phases->first(fn (Phase $phase) => (string) $phase->id === $moved->id);
            if ($phase !== null && ($dates($moved->start) !== $phase->start_date->format('Y-m-d') || $dates($moved->end) !== $phase->end_date?->format('Y-m-d'))) {
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
            if ($start !== null && $start !== $row->start_date->format('Y-m-d')) {
                $row->forceFill(['start_date' => $start])->save();
            }
        }
    }

    /**
     * The rows in weekday order, refusing a weekday named twice.
     *
     * @template T of TrainingDayInput|StepRangeInput
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    private static function byWeekday(array $rows, string $field, string $message): array
    {
        usort($rows, fn (TrainingDayInput|StepRangeInput $a, TrainingDayInput|StepRangeInput $b) => $a->weekday <=> $b->weekday);
        $weekdays = array_map(fn (TrainingDayInput|StepRangeInput $row) => $row->weekday, $rows);
        if (count($weekdays) !== count(array_unique($weekdays))) {
            throw new FieldRefused($field, $message);
        }

        return $rows;
    }

    /**
     * @param  list<WeekdayInput>  $weekdays
     * @return list<int> in order, each once
     */
    private static function weekdays(array $weekdays): array
    {
        $days = array_values(array_unique(array_map(fn (WeekdayInput $day) => $day->weekday, $weekdays)));
        sort($days);

        return $days;
    }
}
