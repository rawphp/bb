<?php

use Tests\Support\ContractDtos;

// C_TRAIN (#46): the train, plan, mesocycle, exercise and workout contracts.

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
