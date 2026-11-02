<?php

use App\Capabilities\Review\Data\ReviewAnswerInput;
use App\Capabilities\Review\Data\ReviewCompleteInput;
use App\Capabilities\WeighIns\Data\StepsLogInput;
use App\Capabilities\WeighIns\Data\WeighInDeleteInput;
use App\Capabilities\WeighIns\Data\WeighInLogInput;
use App\Capabilities\WeighIns\Data\WeighInScheduleSetInput;
use App\Capabilities\WeighIns\Data\WeighInUpdateInput;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\IlluminateServerRuleChecker;
use Rawphp\Capabilities\Schema\InputValidator;
use Rawphp\Capabilities\Schema\SchemaValidationException;

// The Progress, review and weigh-in inputs go through the package's own
// validation: the JSON schema, then the server rules, as a registered
// capability's invoke does.

/**
 * @param  class-string  $input
 * @param  array<string, mixed>  $data
 * @return list<string> the fields the validation names, empty when it passes
 */
function progressInputViolations(string $input, array $data): array
{
    $validator = new InputValidator(serverRules: new IlluminateServerRuleChecker(app('validator')));

    try {
        $validator->validate(new CapabilityDefinition(name: 'progress_input_test', input: $input), $data);
    } catch (SchemaValidationException $e) {
        return array_values(array_unique(array_column($e->violations, 'field')));
    }

    return [];
}

/** @return array<string, array{class-string, array<string, mixed>}> a valid input of each Progress write */
function progressValidInputs(): array
{
    $weighIn = [
        'id' => '01JBX0A1B2C3D4E5F6G7H8J9K0',
        'weighed_at' => '2026-10-12T07:05:00+01:00',
        'weight' => ['value' => 83.3, 'unit' => 'kg'],
        'waist' => ['value' => 84.5, 'unit' => 'cm'],
    ];

    return [
        'weigh_in_log' => [WeighInLogInput::class, $weighIn],
        'weigh_in_log in lb without waist' => [WeighInLogInput::class, [...$weighIn, 'weight' => ['value' => 183.6, 'unit' => 'lb'], 'waist' => null]],
        'weigh_in_update' => [WeighInUpdateInput::class, $weighIn],
        'weigh_in_delete' => [WeighInDeleteInput::class, ['id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
        'weigh_in_schedule_set' => [WeighInScheduleSetInput::class, ['entries' => [['weekdays' => [1, 3, 5], 'time' => '07:00'], ['weekdays' => [7], 'time' => '09:30']]]],
        'weigh_in_schedule_set two days in one entry' => [WeighInScheduleSetInput::class, ['entries' => [['weekdays' => [2, 6], 'time' => '06:45']]]],
        'weigh_in_schedule_set two entries of one day' => [WeighInScheduleSetInput::class, ['entries' => [['weekdays' => [1], 'time' => '07:00'], ['weekdays' => [4], 'time' => '18:00']]]],
        'steps_log' => [StepsLogInput::class, ['date' => '2026-10-12', 'steps' => 6100]],
        'review_answer' => [ReviewAnswerInput::class, ['week_start' => '2026-10-05', 'option' => 'choose_own', 'own_kcal' => 2300, 'moves' => [['from_index' => 0, 'to_index' => 6, 'kcal' => 100]]]],
        'review_complete' => [ReviewCompleteInput::class, ['week_start' => '2026-10-05']],
    ];
}

it('CAP-012 accepts a valid input of each Progress write', function (string $input, array $data) {
    expect(progressInputViolations($input, $data))->toBe([]);
})->with(progressValidInputs());

it('CAP-012 refuses a Progress input and names the field', function (string $case, array $change, string $field) {
    [$input, $data] = progressValidInputs()[$case];

    expect(progressInputViolations($input, [...$data, ...$change]))->toContain($field);
})->with([
    'a weigh-in id that is not a ULID' => ['weigh_in_log', ['id' => 'abc'], 'id'],
    'an update id that is not a ULID' => ['weigh_in_update', ['id' => 'abc'], 'id'],
    'a delete id that is not a ULID' => ['weigh_in_delete', ['id' => 'abc'], 'id'],
    'a weigh-in of 0 kg' => ['weigh_in_log', ['weight' => ['value' => 0, 'unit' => 'kg']], 'weight.value'],
    'a weigh-in of 19 kg' => ['weigh_in_log', ['weight' => ['value' => 19, 'unit' => 'kg']], 'weight.value'],
    'a weigh-in of 1001 lb' => ['weigh_in_log', ['weight' => ['value' => 1001, 'unit' => 'lb']], 'weight.value'],
    'an updated weigh-in of 0 kg' => ['weigh_in_update', ['weight' => ['value' => 0, 'unit' => 'kg']], 'weight.value'],
    'a waist of 0 cm' => ['weigh_in_log', ['waist' => ['value' => 0, 'unit' => 'cm']], 'waist.value'],
    'a waist of 301 cm' => ['weigh_in_update', ['waist' => ['value' => 301, 'unit' => 'cm']], 'waist.value'],
    'a weigh-in in stone' => ['weigh_in_log', ['weight' => ['value' => 13, 'unit' => 'st']], 'weight.unit'],
    'a weigh-in on 12 Oct' => ['weigh_in_log', ['weighed_at' => '12 Oct'], 'weighed_at'],
    'a weigh-in on 30 February' => ['weigh_in_log', ['weighed_at' => '2026-02-30T07:00:00+00:00'], 'weighed_at'],
    'a weigh-in at 25:00' => ['weigh_in_log', ['weighed_at' => '2026-10-10T25:00:00+00:00'], 'weighed_at'],
    'a weigh-in in 2062, past what the column holds' => ['weigh_in_log', ['weighed_at' => '2062-10-10T07:00:00+00:00'], 'weighed_at'],
    'a weigh-in in 0202' => ['weigh_in_log', ['weighed_at' => '0202-10-10T07:00:00+10:00'], 'weighed_at'],
    'an update to 30 February' => ['weigh_in_update', ['weighed_at' => '2026-02-30T07:00:00+00:00'], 'weighed_at'],
    'no schedule entries' => ['weigh_in_schedule_set', ['entries' => []], 'entries'],
    'an entry with no weekdays' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [], 'time' => '07:00'], ['weekdays' => [1, 2], 'time' => '08:00']]], 'entries.0.weekdays'],
    'weekday 0' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [0, 3], 'time' => '07:00']]], 'entries.0.weekdays.0'],
    'weekday 8' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [3, 8], 'time' => '07:00']]], 'entries.0.weekdays.1'],
    'weekday "3"' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [1, '3'], 'time' => '07:00']]], 'entries.0.weekdays.1'],
    'one weekday twice in an entry' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [2, 2, 4], 'time' => '07:00']]], 'entries.0.weekdays.1'],
    'one weekday in two entries' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [1, 3], 'time' => '07:00'], ['weekdays' => [3], 'time' => '18:00']]], 'entries.1.weekdays.0'],
    'one weigh-in day a week (WT-001)' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [1], 'time' => '07:00']]], 'entries'],
    'a reminder at 7:00' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [1, 4], 'time' => '7:00']]], 'entries.0.time'],
    'a reminder at 25:00' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [1, 4], 'time' => '25:00']]], 'entries.0.time'],
    'steps on 12 Oct' => ['steps_log', ['date' => '12 Oct'], 'date'],
    'steps on 30 February' => ['steps_log', ['date' => '2026-02-30'], 'date'],
    'negative steps' => ['steps_log', ['steps' => -1], 'steps'],
    'an unknown review option' => ['review_answer', ['option' => 'skip'], 'option'],
    'a move of 10 kcal' => ['review_answer', ['moves' => [['from_index' => 0, 'to_index' => 1, 'kcal' => 10]]], 'moves.0.kcal'],
    'a move to day 7' => ['review_answer', ['moves' => [['from_index' => 0, 'to_index' => 7, 'kcal' => 100]]], 'moves.0.to_index'],
    'a review of 2026-02-30' => ['review_complete', ['week_start' => '2026-02-30'], 'week_start'],
]);
