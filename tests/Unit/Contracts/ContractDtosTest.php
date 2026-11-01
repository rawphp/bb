<?php

use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Tests\Support\ContractDtos;
use Tests\Support\FixtureKeys;

// Contracts committed ahead of their API issue (docs/plan/build-plan.md, CONTRACT).
// Each name in a tests/fixtures/schemas/<area>.owners.json has DTOs, snapshots
// written from them by `php scripts/schemas.php`, and, for a read, screen fixtures
// that its schema accepts.

$root = dirname(__DIR__, 3);
$names = ContractDtos::ownedNames($root);

/** @return array<string, mixed> */
function contractJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('CAP-032 gives every contracted name an input DTO and keeps each snapshot equal to its DTO', function (string $name) use ($root) {
    $input = ContractDtos::find($root, $name, 'input');
    expect($input)->not->toBeNull("{$name} has no input DTO");

    foreach (['input', 'output'] as $side) {
        $class = ContractDtos::find($root, $name, $side);
        if ($class === null) {
            continue;
        }

        $path = "{$root}/tests/fixtures/schemas/{$name}.{$side}.json";
        expect($path)->toBeFile("run php scripts/schemas.php to write {$name}.{$side}.json");
        expect(file_get_contents($path))->toBe(ContractDtos::snapshot($class), "{$name}.{$side}.json is stale: run php scripts/schemas.php");
    }
})->with($names);

it('CAP-002 gives every contracted read a screen fixture its output schema accepts, with every key', function (string $name) use ($root) {
    if (ContractDtos::find($root, $name, 'output') === null) {
        expect(str_ends_with($name, '_show'))->toBeFalse("{$name} is a read with no output DTO");

        return;
    }

    $schema = contractJson("{$root}/tests/fixtures/schemas/{$name}.output.json");
    $fixtures = [
        "{$root}/tests/fixtures/screens/{$name}.json",
        ...glob("{$root}/tests/fixtures/screens/{$name}.*.json") ?: [],
    ];
    expect($fixtures[0])->toBeFile();

    foreach ($fixtures as $path) {
        $fixture = contractJson($path);
        $file = basename($path);

        expect((new JsonSchemaValidator)->validate($schema, $fixture))->toBe([], "{$file} breaks {$name}.output.json");
        expect(FixtureKeys::missing($schema, $fixture))->toBe([], "{$file} leaves out keys");
    }
})->with($names);

it('gives every contracted name exactly one owning issue', function () use ($root) {
    $seen = [];

    foreach (glob("{$root}/tests/fixtures/schemas/*.owners.json") ?: [] as $path) {
        foreach (contractJson($path) as $name => $issue) {
            expect($name)->toMatch('/^[a-z][a-z0-9_]{0,63}$/')
                ->and($issue)->toBeInt()->toBeGreaterThan(0)
                ->and($seen)->not->toHaveKey($name, "{$name} is owned in two areas");
            $seen[$name] = basename($path);
        }
    }

    expect($seen)->not->toBeEmpty();
});
