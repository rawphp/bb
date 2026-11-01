<?php

use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Rawphp\Capabilities\Support\SchemaSnapshot;
use Tests\Support\ScreenFixture;

// The Today area's contract (C_TODAY): DTOs, schema snapshots and screen fixtures
// that the Today API issues register and the Today views render.

const TODAY_API_ISSUES = [60, 68, 69, 70, 71];

/** @return array<string, int> capability name => issue that registers it */
function todayOwners(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/schemas/today.owners.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** @return class-string the DTO a snapshot is generated from */
function todayDto(string $name, string $side): string
{
    $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $name))).ucfirst($side);

    foreach (['App\\Capabilities\\Reads\\Data\\', 'App\\Capabilities\\Today\\Data\\', 'App\\Capabilities\\Meal\\Data\\'] as $namespace) {
        if (class_exists($namespace.$class)) {
            return $namespace.$class;
        }
    }

    throw new RuntimeException("No DTO {$class} for {$name}");
}

/** @return array<mixed> */
function todaySchema(string $name, string $side): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/schemas/{$name}.{$side}.json")), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<string> the screen fixture files of a read: the full state first, then the others */
function todayFixtureFiles(string $name): array
{
    $states = glob(base_path("tests/fixtures/screens/{$name}.*.json")) ?: [];

    return [base_path("tests/fixtures/screens/{$name}.json"), ...$states];
}

/** @return list<string> */
function todayReads(): array
{
    return array_values(array_filter(array_keys(todayOwners()), fn (string $name) => str_ends_with($name, '_show')));
}

it('CAP-002 gives every Today read and write exactly one owning API issue', function () {
    $raw = (string) file_get_contents(base_path('tests/fixtures/schemas/today.owners.json'));
    preg_match_all('/"([a-z0-9_]+)"\s*:/', $raw, $keys);

    expect($keys[1])->toHaveCount(count(array_unique($keys[1])))
        ->and(todayOwners())->toHaveKeys(['today_show', 'week_show', 'meal_check_in']);

    foreach (todayOwners() as $name => $issue) {
        expect($name)->toMatch('/^[a-z][a-z0-9_]{0,63}$/')
            ->and(TODAY_API_ISSUES)->toContain($issue);
    }
});

it('CAP-032 keeps each Today snapshot equal to the schema of its DTO', function () {
    foreach (todayOwners() as $name => $_) {
        $sides = str_ends_with($name, '_show') ? ['input', 'output'] : ['input'];

        foreach ($sides as $side) {
            $live = todayDto($name, $side)::jsonSchema();

            expect(SchemaSnapshot::schemasEqual(todaySchema($name, $side), $live))
                ->toBeTrue("{$name}.{$side}.json differs from its DTO: regenerate it and run npm run contracts");
        }
    }
});

it('CAP-018 validates every Today screen fixture against its read\'s output schema', function () {
    $validator = new JsonSchemaValidator;

    foreach (todayReads() as $name) {
        $schema = todaySchema($name, 'output');

        foreach (todayFixtureFiles($name) as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            expect($validator->validate($schema, $fixture))->toBe([], basename($path).' does not match its schema');
        }
    }
});

it('CAP-002 keeps every state of a Today screen in the shape of its full fixture', function () {
    foreach (todayReads() as $name) {
        $full = ScreenFixture::load($name);

        foreach (array_slice(todayFixtureFiles($name), 1) as $path) {
            $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            expect(ScreenFixture::differences($full, $state))->toBe([], basename($path));
        }
    }
});

it('SCH-001 points each timeline entry at an item of its kind', function () {
    $lists = ['weigh_in' => 'weigh_ins', 'meal' => 'meals', 'workout' => 'workouts', 'other_activity' => 'other_activities', 'busy' => 'busy_periods'];

    foreach (todayFixtureFiles('today_show') as $path) {
        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['timeline'] as $entry) {
            expect(array_column($fixture[$lists[$entry['kind']]], 'id'))->toContain($entry['id']);
        }
    }
});
