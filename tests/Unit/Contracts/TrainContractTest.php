<?php

use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Tests\Support\ContractDtos;
use Tests\Support\FixtureKeys;

// C_TRAIN (#46): the train, plan, mesocycle, exercise and workout contracts.
// ScreenContractTest holds every snapshot to its DTO and every fixture to its
// schema; these tests add what only the train area promises.

$root = dirname(__DIR__, 3);

/** @return array<string, int> */
function trainOwners(string $root): array
{
    return json_decode((string) file_get_contents("{$root}/tests/fixtures/schemas/train.owners.json"), true, flags: JSON_THROW_ON_ERROR);
}

it('CAP-002 contracts one read per train screen', function () use ($root) {
    $reads = array_values(array_filter(
        array_keys(trainOwners($root)),
        fn (string $name) => ContractDtos::find($root, $name, 'output') !== null,
    ));
    sort($reads);

    expect($reads)->toBe([
        'exercise_show',
        'exercises_show',
        'mesocycle_show',
        'phase_show',
        'plan_show',
        'templates_show',
        'train_show',
        'workout_show',
    ]);
});

it('assigns every train name to one of the area\'s API issues', function () use ($root) {
    foreach (trainOwners($root) as $name => $issue) {
        expect($issue)->toBeIn([78, 79, 80, 81, 82, 83, 84, 85], "{$name} is owned by #{$issue}");
    }

    expect(array_unique(array_values(trainOwners($root))))->toHaveCount(8);
});

it('commits a full and an empty screen fixture for every train read', function () use ($root) {
    foreach (array_keys(trainOwners($root)) as $name) {
        if (ContractDtos::find($root, $name, 'output') === null) {
            continue;
        }

        expect("{$root}/tests/fixtures/screens/{$name}.json")->toBeFile()
            ->and("{$root}/tests/fixtures/screens/{$name}.empty.json")->toBeFile();
    }
});

it('CAP-032 gives every train name an input DTO', function () use ($root) {
    foreach (array_keys(trainOwners($root)) as $name) {
        expect(ContractDtos::find($root, $name, 'input'))->not->toBeNull("{$name} has no input DTO");
    }
});

// The package's DTO schemas mark nullable keys optional, so a fixture could drop
// one and still fit; a train read always sends every key. The package's own
// validator runs too: unlike SchemaCheck it refuses a null an enum leaves out,
// as the API's output check will.
it('CAP-002 sends every key in each train read\'s fixtures, in every state, and passes the package\'s output check', function () use ($root) {
    foreach (array_keys(trainOwners($root)) as $name) {
        if (ContractDtos::find($root, $name, 'output') === null) {
            continue;
        }

        $schema = json_decode((string) file_get_contents("{$root}/tests/fixtures/schemas/{$name}.output.json"), true, flags: JSON_THROW_ON_ERROR);

        foreach (glob("{$root}/tests/fixtures/screens/{$name}{,.*}.json", GLOB_BRACE) ?: [] as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            expect(FixtureKeys::missing($schema, $fixture))->toBe([], basename($path).' leaves out keys')
                ->and((new JsonSchemaValidator)->validate($schema, $fixture))->toBe([], basename($path).' fails the output check');
        }
    }
});
