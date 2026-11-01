<?php

use App\Capabilities\Meal\Data\MealCheckInInput;
use App\Capabilities\Reads\Data\CopyShowInput;
use App\Capabilities\Reads\Data\DayRedistributeShowInput;
use App\Capabilities\Reads\Data\TodayShowInput;
use App\Capabilities\Today\Data\AwayWeekSetInput;
use App\Capabilities\Today\Data\BusyPeriodSetInput;
use App\Capabilities\Today\Data\DayAdjustInput;
use App\Capabilities\Today\Data\DayCopyInput;
use App\Capabilities\Today\Data\MealAddInput;
use App\Capabilities\Today\Data\MealCopyInput;
use App\Capabilities\Today\Data\MealRedistributeInput;
use App\Capabilities\Today\Data\MissedSessionResolveInput;
use App\Capabilities\Today\Data\OffPlanLogInput;
use App\Capabilities\Today\Data\OtherActivityDeleteInput;
use App\Capabilities\Today\Data\OtherActivitySaveInput;
use App\Capabilities\Today\Data\ScheduleUpdateInput;
use App\Capabilities\Today\Data\TrainingWeekdaysChangeInput;
use App\Capabilities\Today\Data\WorkoutMoveInput;
use App\Capabilities\Today\Data\WorkoutTimeSetInput;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\IlluminateServerRuleChecker;
use Rawphp\Capabilities\Schema\InputValidator;
use Rawphp\Capabilities\Schema\SchemaValidationException;

// The Today inputs go through the package's own validation: the JSON schema,
// then the server rules, as a registered capability's invoke does.

const ULID = '01JBX0A1B2C3D4E5F6G7H8J9K0';
const OTHER_ULID = '01JBX0M1N2P3Q4R5S6T7V8W9X0';

/**
 * @param  class-string  $input
 * @param  array<string, mixed>  $data
 * @return list<string> the fields the validation names, empty when it passes
 */
function todayInputViolations(string $input, array $data): array
{
    $validator = new InputValidator(serverRules: new IlluminateServerRuleChecker(app('validator')));

    try {
        $validator->validate(new CapabilityDefinition(name: 'today_input_test', input: $input), $data);
    } catch (SchemaValidationException $e) {
        return array_values(array_unique(array_column($e->violations, 'field')));
    }

    return [];
}

/** @return array<string, array{class-string, array<string, mixed>}> a valid input of each Today write or read with rules */
function todayValidInputs(): array
{
    return [
        'meal_check_in' => [MealCheckInInput::class, ['meal_id' => OTHER_ULID, 'time' => '08:00']],
        'meal_check_in manual' => [MealCheckInInput::class, ['meal_id' => OTHER_ULID, 'kcal' => 300, 'protein_g' => 20, 'fat_g' => 10, 'carbs_g' => 30]],
        'missed_session_resolve' => [MissedSessionResolveInput::class, ['mesocycle_id' => ULID, 'week' => 1, 'day' => 2, 'choice' => 'next_rest_day', 'date' => '2026-09-20']],
        'missed_session_resolve skip' => [MissedSessionResolveInput::class, ['mesocycle_id' => ULID, 'week' => 1, 'day' => 2, 'choice' => 'skip']],
        'day_adjust meals' => [DayAdjustInput::class, ['date' => '2026-09-19', 'mode' => 'meals', 'kcal' => 100, 'from_meal_id' => ULID, 'to_meal_id' => OTHER_ULID]],
        'day_adjust days' => [DayAdjustInput::class, ['date' => '2026-09-19', 'mode' => 'days', 'kcal' => 100, 'other_date' => '2026-09-20']],
        'copy_show meal' => [CopyShowInput::class, ['meal_id' => ULID]],
        'copy_show day' => [CopyShowInput::class, ['source_date' => '2026-09-19']],
        'training_weekdays_change' => [TrainingWeekdaysChangeInput::class, ['weekdays' => [1, 3, 5], 'fewer_days' => 'extend']],
        'training_weekdays_change before the server asks' => [TrainingWeekdaysChangeInput::class, ['weekdays' => [1, 3, 5], 'fewer_days' => null]],
        'meal_redistribute' => [MealRedistributeInput::class, ['date' => '2026-09-19', 'meal_ids' => [ULID, OTHER_ULID]]],
        'meal_copy' => [MealCopyInput::class, ['meal_id' => ULID, 'target_meal_ids' => [OTHER_ULID]]],
        'day_redistribute_show' => [DayRedistributeShowInput::class, ['date' => '2026-09-19', 'excluded_meal_ids' => [ULID]]],
        'other_activity_delete' => [OtherActivityDeleteInput::class, ['id' => ULID]],
        'busy_period_set' => [BusyPeriodSetInput::class, ['id' => ULID, 'label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 7]]],
        'meal_add' => [MealAddInput::class, ['id' => ULID, 'date' => '2026-09-19', 'time' => '14:00']],
        'off_plan_log' => [OffPlanLogInput::class, ['id' => ULID, 'date' => '2026-09-19', 'time' => '15:00', 'kcal' => 320, 'protein_g' => 4, 'fat_g' => 16, 'carbs_g' => 40]],
        'other_activity_save' => [OtherActivitySaveInput::class, ['id' => ULID, 'date' => '2026-09-19', 'name' => 'Tennis', 'start' => '18:45', 'minutes' => 45, 'intensity' => 'moderate']],
        'workout_time_set' => [WorkoutTimeSetInput::class, ['mesocycle_id' => ULID, 'week' => 1, 'day' => 2, 'time' => '17:30']],
        'schedule_update' => [ScheduleUpdateInput::class, ['date' => '2026-09-19', 'apply_to' => 'this_day', 'window_start' => '08:00', 'window_end' => '20:00', 'meal_count' => 4, 'busy_periods' => [['label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30']], 'training_start' => '17:30']],
        'day_copy' => [DayCopyInput::class, ['source_date' => '2026-09-19', 'target_dates' => ['2026-09-20']]],
        'away_week_set' => [AwayWeekSetInput::class, ['week_starts' => ['2026-09-21'], 'away' => true]],
        'workout_move' => [WorkoutMoveInput::class, ['mesocycle_id' => ULID, 'week' => 1, 'day' => 2, 'date' => '2026-09-20']],
        'today_show' => [TodayShowInput::class, ['date' => '2026-09-19']],
    ];
}

it('CAP-012 accepts a valid input of each Today write and read', function (string $input, array $data) {
    expect(todayInputViolations($input, $data))->toBe([]);
})->with(todayValidInputs());

it('CAP-012 refuses a Today input and names the field', function (string $case, array $change, array $remove, string $field) {
    [$input, $data] = todayValidInputs()[$case];
    $data = array_diff_key([...$data, ...$change], array_flip($remove));

    expect(todayInputViolations($input, $data))->toContain($field);
})->with([
    'a check-in with some amounts' => ['meal_check_in manual', [], ['protein_g', 'fat_g', 'carbs_g'], 'protein_g'],
    'a check-in at 25:00' => ['meal_check_in', ['time' => '25:00'], [], 'time'],
    'a missed day moved with no date' => ['missed_session_resolve', [], ['date'], 'date'],
    'a meals adjust with no source meal' => ['day_adjust meals', [], ['from_meal_id'], 'from_meal_id'],
    'a meals adjust with no target meal' => ['day_adjust meals', [], ['to_meal_id'], 'to_meal_id'],
    'a days adjust with no other day' => ['day_adjust days', [], ['other_date'], 'other_date'],
    'a copy sheet with no source' => ['copy_show meal', [], ['meal_id'], 'meal_id'],
    'an unknown fewer-days choice' => ['training_weekdays_change', ['fewer_days' => 'extended'], [], 'fewer_days'],
    'weekday 8' => ['training_weekdays_change', ['weekdays' => [1, 8]], [], 'weekdays.1'],
    'weekday "3"' => ['training_weekdays_change', ['weekdays' => ['3']], [], 'weekdays.0'],
    'a meal id that is not a ULID' => ['meal_redistribute', ['meal_ids' => [ULID, 7]], [], 'meal_ids.1'],
    'a copy target that is not a ULID' => ['meal_copy', ['target_meal_ids' => [[]]], [], 'target_meal_ids.0'],
    'a left-out meal that is not a ULID' => ['day_redistribute_show', ['excluded_meal_ids' => ['abc']], [], 'excluded_meal_ids.0'],
    'an Other activity id with a prefix' => ['other_activity_delete', ['id' => 'other-'.ULID], [], 'id'],
    'a busy period id that is not a ULID' => ['busy_period_set', ['id' => 'abc'], [], 'id'],
    'a busy period from 9:00' => ['busy_period_set', ['start' => '9:00'], [], 'start'],
    'a busy period to 24:00' => ['busy_period_set', ['end' => '24:00'], [], 'end'],
    'a busy period on weekday 0' => ['busy_period_set', ['weekdays' => [0]], [], 'weekdays.0'],
    'a meal added at 2 PM' => ['meal_add', ['time' => '2 PM'], [], 'time'],
    'off-plan food at 15:60' => ['off_plan_log', ['time' => '15:60'], [], 'time'],
    'an activity at 6:45 PM' => ['other_activity_save', ['start' => '6:45 PM'], [], 'start'],
    'a workout at 17.30' => ['workout_time_set', ['time' => '17.30'], [], 'time'],
    'a window from 8' => ['schedule_update', ['window_start' => '8'], [], 'window_start'],
    'a window to 20:00:00' => ['schedule_update', ['window_end' => '20:00:00'], [], 'window_end'],
    'training at 5:30 PM' => ['schedule_update', ['training_start' => '5:30 PM'], [], 'training_start'],
    'a schedule busy period from 9' => ['schedule_update', ['busy_periods' => [['label' => 'Team meeting', 'start' => '9', 'end' => '10:30']]], [], 'busy_periods.0.start'],
    'a schedule busy period to 10.30' => ['schedule_update', ['busy_periods' => [['label' => 'Team meeting', 'start' => '09:00', 'end' => '10.30']]], [], 'busy_periods.0.end'],
    'a missed day moved to 20 Sep' => ['missed_session_resolve', ['date' => '20 Sep'], [], 'date'],
    'a days adjust with 2026-02-30' => ['day_adjust days', ['other_date' => '2026-02-30'], [], 'other_date'],
    'an adjust of 2026-9-19' => ['day_adjust meals', ['date' => '2026-9-19'], [], 'date'],
    'a meal added on 19/09/2026' => ['meal_add', ['date' => '19/09/2026'], [], 'date'],
    'a copy sheet from tomorrow' => ['copy_show day', ['source_date' => 'tomorrow'], [], 'source_date'],
    'a workout moved to 2026-02-30' => ['workout_move', ['date' => '2026-02-30'], [], 'date'],
    'Today for tomorrow' => ['today_show', ['date' => 'tomorrow'], [], 'date'],
    'a copy to 20 Sep' => ['day_copy', ['target_dates' => ['20 Sep']], [], 'target_dates.0'],
    'an away week of 2026-02-30' => ['away_week_set', ['week_starts' => ['2026-02-30']], [], 'week_starts.0'],
]);
