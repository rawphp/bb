<?php

use App\Capabilities\Settings\Data\ScheduleDefaultsUpdateInput;
use App\Capabilities\Tokens\Data\TokenCreateInput;
use App\Capabilities\Tokens\Data\TokenRevokeInput;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\IlluminateServerRuleChecker;
use Rawphp\Capabilities\Schema\InputValidator;
use Rawphp\Capabilities\Schema\SchemaValidationException;

// The Settings inputs' server rules go through the package's own validation:
// the JSON schema, then the server rules, as a registered capability's invoke does.

/**
 * @param  class-string  $input
 * @param  array<string, mixed>  $data
 * @return list<string> the fields the validation names, empty when it passes
 */
function settingsInputViolations(string $input, array $data): array
{
    $validator = new InputValidator(serverRules: new IlluminateServerRuleChecker(app('validator')));

    try {
        $validator->validate(new CapabilityDefinition(name: 'settings_input_test', input: $input), $data);
    } catch (SchemaValidationException $e) {
        return array_values(array_unique(array_column($e->violations, 'field')));
    }

    return [];
}

/** @return array<string, mixed> */
function validScheduleDefaults(): array
{
    return [
        'eating_window_start' => '07:30',
        'eating_window_end' => '20:00',
        'meal_count' => 4,
        'training_days' => [['weekday' => 1, 'start' => '18:00', 'minutes' => 60]],
        'busy_periods' => [['label' => 'Work', 'start' => '09:00', 'end' => '17:00', 'weekdays' => [['weekday' => 1]]]],
        'step_plan' => [['weekday' => 1, 'min' => 8000, 'max' => 10000]],
        'review_weekday' => 7,
    ];
}

it('CAP-019 takes a new token\'s id and a revoked token\'s id only as a ULID', function () {
    $create = ['id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'name' => 'Laptop', 'expires_in_days' => 90, 'scope' => 'everything', 'capabilities' => []];

    expect(settingsInputViolations(TokenCreateInput::class, $create))->toBe([])
        ->and(settingsInputViolations(TokenCreateInput::class, ['id' => 'abc'] + $create))->toBe(['id'])
        ->and(settingsInputViolations(TokenRevokeInput::class, ['id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']))->toBe([])
        ->and(settingsInputViolations(TokenRevokeInput::class, ['id' => '42']))->toBe(['id']);
});

it('SET-005 takes every Schedule defaults time only as 24-hour HH:mm', function (string $field, Closure $break) {
    expect(settingsInputViolations(ScheduleDefaultsUpdateInput::class, validScheduleDefaults()))->toBe([])
        ->and(settingsInputViolations(ScheduleDefaultsUpdateInput::class, $break(validScheduleDefaults())))->toBe([$field]);
})->with([
    'eating window start' => ['eating_window_start', fn (array $d) => ['eating_window_start' => '7:30pm'] + $d],
    'eating window end' => ['eating_window_end', fn (array $d) => ['eating_window_end' => '24:00'] + $d],
    'training day start' => ['training_days.0.start', function (array $d) {
        $d['training_days'][0]['start'] = '6pm';

        return $d;
    }],
    'busy period start' => ['busy_periods.0.start', function (array $d) {
        $d['busy_periods'][0]['start'] = '9';

        return $d;
    }],
    'busy period end' => ['busy_periods.0.end', function (array $d) {
        $d['busy_periods'][0]['end'] = '17:60';

        return $d;
    }],
]);
