<?php

use App\Capabilities\Exercises\Data\ExerciseLoadStepSetInput;
use App\Capabilities\Mesocycles\Data\MesocycleBuildInput;
use App\Capabilities\Mesocycles\Data\MesocycleLengthSetInput;
use App\Capabilities\Support\Tone;
use App\Capabilities\Workout\Data\WorkoutExerciseReorderInput;
use App\Capabilities\Workout\Data\WorkoutSetLogInput;
use App\Capabilities\Workout\Data\WorkoutShowInput;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Sex;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\WeightUnit;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Schema\IlluminateServerRuleChecker;
use Rawphp\Capabilities\Schema\InputValidator;
use Rawphp\Capabilities\Schema\SchemaValidationException;
use Rawphp\Capabilities\Support\CapabilityData;
use Tests\Support\ContractDtos;

// The train inputs go through the package's own validation: the JSON schema,
// then the server rules, as a registered capability's invoke does (CAP-012).
// Each write gets an input built from its schema, so a new write is covered
// the day it joins train.owners.json.

const TRAIN_ULID = '01JBX0A1B2C3D4E5F6G7H8J9K0';

/** @return array<string, class-string<CapabilityData>> capability name → input DTO */
function trainInputs(): array
{
    $root = dirname(__DIR__, 3);
    $owners = json_decode((string) file_get_contents("{$root}/tests/fixtures/schemas/train.owners.json"), true, flags: JSON_THROW_ON_ERROR);
    $inputs = [];

    foreach (array_keys($owners) as $name) {
        $inputs[$name] = ContractDtos::find($root, $name, 'input') ?? throw new LogicException("{$name} has no input DTO");
    }

    return $inputs;
}

/**
 * A value that fits the schema, with every optional key filled in: the first
 * enum value, the lowest bound, a ULID for an id, a date for a date.
 *
 * @param  array<string, mixed>  $schema
 */
function trainExample(array $schema, string $key = ''): mixed
{
    $enum = array_values(array_filter($schema['enum'] ?? [], fn ($value) => $value !== null));
    if ($enum !== []) {
        return $enum[0];
    }

    $type = array_values(array_diff((array) ($schema['type'] ?? 'object'), ['null']))[0];

    return match ($type) {
        'object' => array_map(fn (string $name) => trainExample($schema['properties'][$name], $name), array_combine(
            array_keys($schema['properties'] ?? []),
            array_keys($schema['properties'] ?? []),
        )),
        'array' => array_fill(0, max(1, $schema['minItems'] ?? 1), trainExample($schema['items'] ?? ['type' => 'string'])),
        'integer' => (int) ($schema['minimum'] ?? 1),
        'number' => (float) ($schema['minimum'] ?? 1),
        'boolean' => true,
        default => match (true) {
            ($schema['format'] ?? null) === 'date' => '2026-11-02',
            $key === 'id' || str_ends_with($key, '_id') => TRAIN_ULID,
            default => str_repeat('x', max(1, $schema['minLength'] ?? 1)),
        },
    };
}

/**
 * @param  class-string<CapabilityData>  $input
 * @param  array<string, mixed>  $data
 * @return list<string> the fields the validation names, empty when it passes
 */
function trainInputViolations(string $input, array $data): array
{
    $validator = new InputValidator(serverRules: new IlluminateServerRuleChecker(app('validator')));

    try {
        $validator->validate(new CapabilityDefinition(name: 'train_input_test', input: $input), $data);
    } catch (SchemaValidationException $e) {
        return array_values(array_unique(array_column($e->violations, 'field')));
    }

    return [];
}

it('CAP-012 accepts an input of each train write and read built from its schema', function (string $input) {
    expect(trainInputViolations($input, trainExample($input::jsonSchema())))->toBe([]);
})->with(trainInputs());

it('CAP-019 refuses an id that is not a ULID in every train ULID rule, nested ones included', function () {
    $checked = [];

    foreach (trainInputs() as $name => $input) {
        foreach (array_keys($input::rules()) as $field) {
            $data = trainExample($input::jsonSchema());
            data_set($data, str_replace('*', '0', $field), 'not-a-ulid');

            expect(in_array(str_replace('*', '0', $field), trainInputViolations($input, $data), true))->toBeTrue("{$name} accepts a bad {$field}");
            $checked[] = "{$name}.{$field}";
        }
    }

    expect($checked)->toContain(
        'mesocycle_build.days.*.day_id',
        'mesocycle_build.days.*.slots.*.slot_id',
        'workout_set_add.after_set_id',
        'exercise_swap.new_workout_exercise_id',
    )->and(count($checked))->toBeGreaterThanOrEqual(30);
});

it('CAP-012 lets a train input send null for a nullable enum, and leave out an optional one', function () {
    $checked = 0;

    foreach (trainInputs() as $name => $input) {
        foreach ($input::jsonSchema()['properties'] ?? [] as $field => $property) {
            if (! isset($property['enum']) || ! in_array('null', (array) $property['type'], true)) {
                continue;
            }

            $data = trainExample($input::jsonSchema());

            expect(trainInputViolations($input, [...$data, $field => null]))->toBe([], "{$name} refuses {$field}: null");
            if (! in_array($field, $input::jsonSchema()['required'] ?? [], true)) {
                unset($data[$field]);
                expect(trainInputViolations($input, $data))->toBe([], "{$name} refuses {$field} left out");
            }
            $checked++;
        }
    }

    expect($checked)->toBe(9);
});

it('CAP-012 refuses a train input outside its bounds and names the field', function (string $input, string $field, mixed $value) {
    $data = trainExample($input::jsonSchema());
    data_set($data, $field, $value);

    expect(trainInputViolations($input, $data))->toContain($field);
})->with([
    'a zero load step' => [ExerciseLoadStepSetInput::class, 'step', 0.0],
    'a negative load step' => [ExerciseLoadStepSetInput::class, 'step', -2.5],
    'a slot of 21 sets' => [MesocycleBuildInput::class, 'days.0.slots.0.sets', 21],
    'a slot of no sets' => [MesocycleBuildInput::class, 'days.0.slots.0.sets', 0],
    'a 9-week mesocycle' => [MesocycleLengthSetInput::class, 'weeks', 9],
    'position 0' => [WorkoutExerciseReorderInput::class, 'position', 0],
    'negative reps' => [WorkoutSetLogInput::class, 'reps', -1],
    'week 0' => [WorkoutShowInput::class, 'week', 0],
    'day 0' => [WorkoutShowInput::class, 'day', 0],
]);

// A contract value the API maps with Enum::from() must be one of the enum's
// values, or the request ends in a ValueError. Each property name below holds
// one domain enum wherever it appears in a train schema.
it('CAP-002 uses the domain enums\' values in every train contract', function () {
    $root = dirname(__DIR__, 3);
    $enums = [
        'muscle' => [MuscleGroup::class, []],
        'muscle_group' => [MuscleGroup::class, []],
        'equipment' => [Equipment::class, []],
        'load_type' => [ExerciseLoadType::class, []],
        'unit' => [WeightUnit::class, []],
        'weight_unit' => [WeightUnit::class, []],
        'sex' => [Sex::class, []],
        'pump' => [Pump::class, []],
        'volume' => [VolumeFeedback::class, []],
        'soreness' => [Soreness::class, []],
        'answer' => [JointPain::class, []],
        'priority' => [Priority::class, []],
        'type' => [PhaseType::class, []],
        'default_type' => [PhaseType::class, []],
        'choice' => [PhaseType::class, ['gain_first']],
        'tone' => [Tone::class, []],
    ];
    $checked = 0;

    $walk = function (array $schema, string $where) use (&$walk, &$checked, $enums): void {
        foreach ($schema['properties'] ?? [] as $key => $property) {
            foreach ([$property, $property['items'] ?? []] as $node) {
                if (isset($node['enum'], $enums[$key])) {
                    [$enum, $extra] = $enums[$key];
                    $values = array_values(array_filter($node['enum'], fn ($value) => $value !== null));
                    $expected = [...array_column($enum::cases(), 'value'), ...$extra];
                    sort($values);
                    sort($expected);

                    expect($values)->toBe($expected, "{$where}.{$key}");
                    $checked++;
                }
            }

            $walk($property, "{$where}.{$key}");
            $walk($property['items'] ?? [], "{$where}.{$key}[]");
        }
    };

    foreach (array_keys(trainInputs()) as $name) {
        foreach (['input', 'output'] as $side) {
            $class = ContractDtos::find($root, $name, $side);
            if ($class !== null) {
                $walk($class::jsonSchema(), "{$name}.{$side}");
            }
        }
    }

    expect($checked)->toBeGreaterThan(20);
});
