<?php

use Illuminate\Support\Str;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Rawphp\Capabilities\Support\SchemaSnapshot;
use Tests\Support\ScreenFixture;

// The Today area's contract (C_TODAY): DTOs, schema snapshots and screen fixtures
// that the Today API issues register and the Today views render.

const TODAY_API_ISSUES = [60, 68, 69, 70, 71, 236, 239];

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

/**
 * @param  array<mixed>  $schema
 * @return list<string> paths of the objects and lists the schema lets be null, list items as []
 */
function todayNullableObjectPaths(array $schema, string $path = '$'): array
{
    $types = (array) ($schema['type'] ?? []);
    $paths = in_array('null', $types, true) && array_intersect(['object', 'array'], $types) !== [] ? [$path] : [];

    foreach ($schema['properties'] ?? [] as $key => $property) {
        array_push($paths, ...todayNullableObjectPaths($property, "{$path}.{$key}"));
    }
    if (isset($schema['items']) && is_array($schema['items'])) {
        array_push($paths, ...todayNullableObjectPaths($schema['items'], "{$path}[]"));
    }

    return $paths;
}

/** @return list<string> paths of the objects and lists a fixture fills in, list items as [] */
function todayFilledPaths(mixed $value, string $path = '$'): array
{
    if (! is_array($value)) {
        return [];
    }

    $paths = [$path];
    foreach ($value as $key => $item) {
        array_push($paths, ...todayFilledPaths($item, array_is_list($value) ? "{$path}[]" : "{$path}.{$key}"));
    }

    return $paths;
}

it('CAP-002 fills in every nullable object of a Today read in at least one of its fixtures', function () {
    // A null in the full fixture leaves the shape below it unchecked, and the
    // views have no example of it, so some state must show each one filled in.
    foreach (todayReads() as $name) {
        $filled = [];
        foreach (todayFixtureFiles($name) as $path) {
            array_push($filled, ...todayFilledPaths(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)));
        }

        expect(array_values(array_diff(todayNullableObjectPaths(todaySchema($name, 'output')), $filled)))->toBe([], "{$name}: no fixture fills these in");
    }
});

it('CAP-019 gives an Other activity card the stored ULID that its writes take', function () {
    foreach (todayFixtureFiles('today_show') as $path) {
        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($fixture['other_activities'] as $activity) {
            expect(Str::isUlid($activity['id']))->toBeTrue(basename($path).": {$activity['id']} is not a ULID");
        }
        if ($fixture['add_workout']['activity'] !== null) {
            expect(array_column($fixture['other_activities'], 'id'))->toContain($fixture['add_workout']['activity']['id']);
        }
    }
});
