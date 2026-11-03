<?php

namespace App\Actions\Settings;

use App\Capabilities\Settings\Data\BusyPeriodInput;
use App\Capabilities\Settings\Data\ScheduleDefaultsUpdateInput;
use App\Capabilities\Settings\Data\StepRangeInput;
use App\Capabilities\Settings\Data\TrainingDayInput;
use App\Capabilities\Settings\Data\WeekdayInput;
use App\Capabilities\Support\FieldRefused;
use App\Models\Profile;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\TodaySnapshot;

/**
 * SET-005: the Schedule defaults form saves the eating window, meal count, training days, busy periods and step plan
 * on the profile, then rebuilds the targets (SET-004, {@see SaveProfile}). Weigh-in days are not on this form.
 *
 * SCH-039: while a mesocycle covers next plan week, a different set of training weekdays would leave its sessions on
 * the old days, so the write is refused and `training_weekdays_change` moves them instead. New times or minutes on the
 * same weekdays save as usual.
 *
 * The review day is shown here, but changing it from the next plan-week boundary (PLAN-008) is not built yet, so only
 * the plan's own day, or null, is accepted.
 */
final class UpdateScheduleDefaults
{
    public const MESOCYCLE_UNDER_WAY = 'A mesocycle is under way. Change training weekdays to move its sessions from next week.';

    public const REVIEW_DAY_CHANGE = "Changing the review day isn't supported yet.";

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

    /** Runs under the profile's lock, before anything saves. */
    private static function check(User $user, Profile $profile, ScheduleDefaultsUpdateInput $input): void
    {
        $plan = PlanSnapshot::load($user);

        if ($input->review_weekday !== null) {
            if ($plan === null) {
                throw new FieldRefused('review_weekday', self::NO_PLAN_REVIEW_DAY);
            }
            if ($input->review_weekday !== $plan->plan->review_weekday) {
                throw new FieldRefused('review_weekday', self::REVIEW_DAY_CHANGE);
            }
        }

        $stored = array_map(fn (array $day): int => (int) $day['weekday'], TodaySnapshot::json($profile->training_days));
        $sent = array_map(fn (TrainingDayInput $day): int => $day->weekday, $input->training_days);
        sort($stored);
        sort($sent);
        if ($stored !== array_values(array_unique($sent)) && $plan?->mesocycleCoveringWeek($plan->nextWeek()) !== null) {
            throw new FieldRefused('training_days', self::MESOCYCLE_UNDER_WAY);
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
