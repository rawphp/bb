<?php

use App\Capabilities\Support\Tone;
use App\Capabilities\Support\WriteOutput;
use App\Capabilities\Support\WriteResult;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Support\CapabilityData;

// The Settings area's contracts (C_SETTINGS): DTOs, schema snapshots and screen
// fixtures, committed before the API issues named in settings.owners.json register them.
// ScreenContractTest checks every snapshot against its DTO and every fixture state
// against its output schema; this file checks that each Settings name has them all.

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

it('CAP-002 gives each Settings-area read its full screen fixture', function () {
    foreach (array_filter(array_keys(settingsOwners()), isSettingsRead(...)) as $name) {
        expect(base_path("tests/fixtures/screens/{$name}.json"))->toBeFile();
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

it('CAP-010 builds every Settings-area write output on WriteOutput, so it declares no CAP-010 field of its own', function () {
    $fields = array_keys(WriteResult::jsonSchema()['properties']);
    $checked = 0;

    foreach (array_keys(settingsOwners()) as $name) {
        $dto = isSettingsRead($name) ? null : settingsDto($name, 'output');
        if ($dto === null) {
            continue;
        }

        $own = array_map(fn (ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod($dto, '__construct'))->getParameters());
        expect(is_subclass_of($dto, WriteOutput::class))->toBeTrue("{$dto} extends WriteOutput")
            ->and(array_intersect($own, $fields))->toBe([], "{$dto} re-declares a CAP-010 field");
        $checked++;
    }

    expect($checked)->toBe(4);
});
