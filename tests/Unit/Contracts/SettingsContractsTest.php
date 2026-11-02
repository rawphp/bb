<?php

use App\Capabilities\Support\Tone;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Support\CapabilityData;
use Rawphp\Capabilities\Support\SchemaSnapshot;
use Tests\Support\SchemaShape;

// The Settings area's contracts (C_SETTINGS): DTOs, schema snapshots and screen
// fixtures, committed before the API issues named in settings.owners.json register them.

/** @return array<string, int> */
function settingsOwners(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/schemas/settings.owners.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed>|null */
function settingsSnapshot(string $name, string $side): ?array
{
    $path = base_path("tests/fixtures/schemas/{$name}.{$side}.json");

    return is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : null;
}

/** @return class-string<CapabilityData>|null the DTO at app/Capabilities/<Module>/Data/<StudlyName><Side>.php */
function settingsDto(string $name, string $side): ?string
{
    $paths = glob(app_path('Capabilities/*/Data/'.Str::studly($name).Str::studly($side).'.php')) ?: [];
    if (count($paths) !== 1) {
        return null;
    }

    /** @var class-string<CapabilityData> */
    return 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($paths[0], app_path().'/'));
}

function isSettingsRead(string $name): bool
{
    return preg_match('/_(show|list)$/', $name) === 1;
}

it('CAP-032 gives every Settings-area name exactly one owner, a DTO and a schema snapshot', function () {
    $raw = (string) file_get_contents(base_path('tests/fixtures/schemas/settings.owners.json'));
    preg_match_all('/^\s*"([^"]+)"\s*:/m', $raw, $keys);
    expect($keys[1])->toHaveCount(count(array_unique($keys[1])));

    foreach (settingsOwners() as $name => $issue) {
        expect($name)->toMatch('/^[a-z][a-z0-9_]{0,63}$/')
            ->and($issue)->toBeInt()->toBeGreaterThan(0)
            ->and(settingsDto($name, 'input'))->not->toBeNull("{$name} has no input DTO")
            ->and(settingsSnapshot($name, 'input'))->not->toBeNull("{$name} has no input snapshot");

        if (isSettingsRead($name)) {
            expect(settingsSnapshot($name, 'output'))->not->toBeNull("{$name} has no output snapshot");
        }
    }
});

it('CAP-032 keeps each Settings-area snapshot equal to its DTO\'s schema', function () {
    foreach (array_keys(settingsOwners()) as $name) {
        foreach (['input', 'output'] as $side) {
            $dto = settingsDto($name, $side);
            $snapshot = settingsSnapshot($name, $side);
            expect($dto === null)->toBe($snapshot === null, "{$name}.{$side}: a DTO and a snapshot come together");

            if ($dto !== null) {
                expect(SchemaSnapshot::schemasEqual($snapshot, $dto::jsonSchema()))
                    ->toBeTrue("{$name}.{$side}.json differs from {$dto}");
            }
        }
    }
});

it('CAP-002 gives each Settings-area read a screen fixture whose every state fits its output schema', function () {
    foreach (array_filter(array_keys(settingsOwners()), isSettingsRead(...)) as $name) {
        $states = glob(base_path("tests/fixtures/screens/{$name}{,.*}.json"), GLOB_BRACE) ?: [];
        expect($states)->toContain(base_path("tests/fixtures/screens/{$name}.json"));

        /** @var array<string, mixed> $schema */
        $schema = settingsSnapshot($name, 'output');
        foreach ($states as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            expect(SchemaShape::errors($schema, $fixture))->toBe([], basename($path));
        }
    }
});

it('CAP-018 gives every Settings-area status its text and a server-picked tone', function () {
    $tones = array_map(fn (Tone $tone): string => $tone->value, Tone::cases());
    $found = 0;

    $walk = function (array $schema) use (&$walk, &$found, $tones): void {
        $properties = $schema['properties'] ?? [];
        if (isset($properties['tone'])) {
            expect($properties['tone']['enum'] ?? null)->toBe($tones)
                ->and($schema['required'] ?? [])->toContain('text', 'tone');
            $found++;
        }
        foreach ($schema as $value) {
            if (is_array($value)) {
                $walk($value);
            }
        }
    };

    foreach (array_keys(settingsOwners()) as $name) {
        $walk(settingsSnapshot($name, 'output') ?? []);
    }

    expect($found)->toBeGreaterThan(0);
});

it('lets SchemaShape report a wrong type, a missing key, an extra key and a value outside the enum', function () {
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'text' => ['type' => 'string'],
            'tone' => ['type' => 'string', 'enum' => ['good', 'warning']],
            'count' => ['type' => ['number', 'null']],
        ],
        'required' => ['text', 'tone'],
    ];

    expect(SchemaShape::errors($schema, ['text' => 'On', 'tone' => 'good', 'count' => 3]))->toBe([])
        ->and(SchemaShape::errors($schema, ['text' => 1, 'tone' => 'bad', 'extra' => true]))->toBe([
            '$.text: expected string, got integer',
            '$.tone: "bad" is not one of ["good","warning"]',
            '$.extra: not in the schema',
        ])
        ->and(SchemaShape::errors($schema, ['tone' => 'good']))->toBe(['$.text: missing']);
});
