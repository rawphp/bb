<?php

use App\Capabilities\Support\Tone;
use Rawphp\Capabilities\Support\SchemaSnapshot;
use Tests\Support\SchemaCheck;
use Tests\Support\ScreenFixture;

// A contract is a DTO, its schema snapshot and, for a read, its screen fixtures.
// These tests hold the three together before any API issue registers the capability.

/** @return class-string The DTO under app/Capabilities/<Module>/Data/ for a capability name and side. */
function contractDto(string $name, string $side): string
{
    $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $name))).ucfirst($side);
    $paths = glob(app_path("Capabilities/*/Data/{$studly}.php")) ?: [];

    expect($paths)->toHaveCount(1, "Expected one app/Capabilities/*/Data/{$studly}.php");

    /** @var class-string */
    return 'App\\Capabilities\\'.basename(dirname($paths[0], 2)).'\\Data\\'.$studly;
}

/** @return array<string, mixed> */
function decodeJsonFile(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('CAP-032 keeps each schema snapshot equal to its DTO\'s schema', function () {
    $paths = glob(base_path('tests/fixtures/schemas/*.{input,output}.json'), GLOB_BRACE) ?: [];

    foreach ($paths as $path) {
        preg_match('~/([a-z0-9_]+)\.(input|output)\.json$~', $path, $match);
        $dto = contractDto($match[1], $match[2]);

        expect(SchemaSnapshot::schemasEqual(decodeJsonFile($path), $dto::jsonSchema()))
            ->toBeTrue(basename($path)." differs from {$dto}::jsonSchema()");
    }

    expect($paths)->not->toBeEmpty();
});

// tests/fixtures/screens/<name>.json is the full state that assertMatchesScreenFixture
// compares with. <name>.<state>.json holds another state (empty, warning) for the views.
it('CAP-002 fits every screen fixture, in every state, to its read\'s output schema', function () {
    $paths = glob(base_path('tests/fixtures/screens/*.json')) ?: [];

    foreach ($paths as $path) {
        $name = explode('.', basename($path))[0];
        $snapshot = base_path("tests/fixtures/schemas/{$name}.output.json");

        expect(is_file($snapshot))->toBeTrue(basename($path)." has no {$name}.output.json");
        expect(SchemaCheck::violations(decodeJsonFile($snapshot), decodeJsonFile($path)))->toBe([], basename($path));
    }

    expect($paths)->not->toBeEmpty();
});

it('CAP-002 gives every state of a screen a full fixture to match the API against', function () {
    foreach (glob(base_path('tests/fixtures/screens/*.*.json')) ?: [] as $path) {
        $name = explode('.', basename($path))[0];

        expect(fn () => ScreenFixture::load($name))->not->toThrow(InvalidArgumentException::class);
    }
});

// <area>.owners.json maps each contract name to the API issue that registers it.
it('CAP-002 gives each contract name in an owners file one owner and a schema snapshot', function () {
    $seen = [];

    foreach (glob(base_path('tests/fixtures/schemas/*.owners.json')) ?: [] as $path) {
        foreach (decodeJsonFile($path) as $name => $issue) {
            expect($seen)->not->toHaveKey($name, "{$name} is owned in two owners files");
            expect($issue)->toBeInt()->toBeGreaterThan(0);
            expect(glob(base_path("tests/fixtures/schemas/{$name}.{input,output}.json"), GLOB_BRACE))
                ->not->toBeEmpty("{$name} in ".basename($path).' has no schema snapshot');
            $seen[$name] = basename($path);
        }
    }
});

it('CAP-018 sends every tone as one of the UI-004 tones', function () {
    $tones = array_column(Tone::cases(), 'value');
    $found = 0;

    foreach (glob(base_path('tests/fixtures/schemas/*.output.json')) ?: [] as $path) {
        $walk = function (array $node) use (&$walk, $tones, &$found, $path): void {
            foreach ($node as $key => $value) {
                if ($key === 'tone' && is_array($value)) {
                    expect($value['enum'] ?? null)->toBe($tones, basename($path));
                    $found++;
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk(decodeJsonFile($path));
    }

    expect($found)->toBeGreaterThan(0);
});
