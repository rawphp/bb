<?php

use App\Capabilities\Exercises\Data\CustomExerciseSaveInput;
use App\Capabilities\Exercises\Data\ExerciseLoadStepSetInput;
use App\Capabilities\Exercises\Data\ExerciseNoteSetInput;
use App\Capabilities\Mesocycles\Data\MesocycleBuildInput;
use App\Capabilities\Mesocycles\Data\MesocycleLengthSetInput;
use App\Capabilities\Plan\Data\PhaseAddInput;
use App\Capabilities\Plan\Data\PhaseLengthSetInput;
use App\Capabilities\Plan\Data\PhaseTransitionCompleteInput;
use App\Capabilities\Support\Tone;
use App\Capabilities\Workout\Data\WorkoutDayRelabelInput;
use App\Capabilities\Workout\Data\WorkoutExerciseNoteSetInput;
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
    // workout_show's mesocycle_id has no ULID rule: mesocycle ids are UUIDs, as in mesocycle_show.
    )->and(count($checked))->toBeGreaterThanOrEqual(29);
});

it('CAP-012 lets a train input send null for a nullable enum, and leave it out only when it has a default', function () {
    $checked = 0;

    foreach (trainInputs() as $name => $input) {
        foreach ($input::jsonSchema()['properties'] ?? [] as $field => $property) {
            if (! isset($property['enum']) || ! in_array('null', (array) $property['type'], true)) {
                continue;
            }

            $data = trainExample($input::jsonSchema());

            expect(trainInputViolations($input, [...$data, $field => null]))->toBe([], "{$name} refuses {$field}: null");

            $required = ! (new ReflectionParameter([$input, '__construct'], $field))->isDefaultValueAvailable();
            unset($data[$field]);
            expect(trainInputViolations($input, $data))->toBe($required ? [$field] : [], "{$name} {$field} left out");
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
    'a phase of 0 weeks' => [PhaseAddInput::class, 'weeks', 0],
    'a phase length of 0 weeks' => [PhaseLengthSetInput::class, 'weeks', 0],
    'a next phase of 0 weeks' => [PhaseTransitionCompleteInput::class, 'weeks', 0],
    'an empty day label' => [WorkoutDayRelabelInput::class, 'label', ''],
    'a 61-character day label' => [WorkoutDayRelabelInput::class, 'label', str_repeat('x', 61)],
    'an empty exercise name' => [CustomExerciseSaveInput::class, 'name', ''],
    'a 256-character exercise name' => [CustomExerciseSaveInput::class, 'name', str_repeat('x', 256)],
    'an empty exercise note' => [ExerciseNoteSetInput::class, 'note', ''],
    'a 1001-character exercise note' => [ExerciseNoteSetInput::class, 'note', str_repeat('x', 1001)],
    'an empty session note' => [WorkoutExerciseNoteSetInput::class, 'note', ''],
    'a 1001-character session note' => [WorkoutExerciseNoteSetInput::class, 'note', str_repeat('x', 1001)],
]);

it('CAP-012 takes the values at a train input\'s bounds, and the null that clears or resets', function (string $input, string $field, mixed $value) {
    $data = trainExample($input::jsonSchema());
    data_set($data, $field, $value);

    expect(trainInputViolations($input, $data))->toBe([]);
})->with([
    'the smallest load step' => [ExerciseLoadStepSetInput::class, 'step', 0.25],
    'a null load step, back to the default' => [ExerciseLoadStepSetInput::class, 'step', null],
    'a null exercise note, which clears it' => [ExerciseNoteSetInput::class, 'note', null],
    'a null session note, which clears it' => [WorkoutExerciseNoteSetInput::class, 'note', null],
    'a 1000-character note' => [ExerciseNoteSetInput::class, 'note', str_repeat('x', 1000)],
    'a 60-character day label' => [WorkoutDayRelabelInput::class, 'label', str_repeat('x', 60)],
    'an 8-week mesocycle' => [MesocycleLengthSetInput::class, 'weeks', 8],
    'a slot of 20 sets' => [MesocycleBuildInput::class, 'days.0.slots.0.sets', 20],
    'a phase with no end' => [PhaseAddInput::class, 'weeks', null],
]);

// A nullable field with no default means something when null (a cleared note,
// no end, the default step), so a payload that leaves the key out is refused
// rather than read as that command.
it('CAP-012 refuses a train input that leaves out a nullable field with no default', function () {
    $checked = [];

    foreach (trainInputs() as $name => $input) {
        foreach ((new ReflectionClass($input))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (! $parameter->allowsNull() || $parameter->isDefaultValueAvailable()) {
                continue;
            }

            $data = trainExample($input::jsonSchema());
            unset($data[$parameter->getName()]);

            expect(trainInputViolations($input, $data))->toBe([$parameter->getName()], "{$name} accepts a missing {$parameter->getName()}");
            $checked[] = "{$name}.{$parameter->getName()}";
        }
    }

    expect($checked)->toContain('exercise_note_set.note', 'phase_length_set.weeks', 'phase_event_date_set.event_date', 'priority_set.priority');
});

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
        'choice' => [PhaseType::class, []],
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
