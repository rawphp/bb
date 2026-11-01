<?php

use App\Capabilities\Support\Tone;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Support\CapabilityData;
use Tests\Support\SchemaCheck;

// Contracts land before the capabilities that register them, so the snapshot
// is compared with its DTO here, and every screen fixture with its snapshot.

/** @return array<string, mixed> */
function contractJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/** @return class-string<CapabilityData>|null */
function contractDto(string $name, string $side): ?string
{
    $class = Str::studly($name).Str::studly($side);
    $files = glob(app_path("Capabilities/*/Data/{$class}.php")) ?: [];

    if (count($files) !== 1) {
        return null;
    }

    $module = basename(dirname($files[0], 2));

    /** @var class-string<CapabilityData> */
    return "App\\Capabilities\\{$module}\\Data\\{$class}";
}

it('CAP-032 keeps each schema snapshot equal to the schema of its DTO', function () {
    $snapshots = glob(base_path('tests/fixtures/schemas/*.{input,output}.json'), GLOB_BRACE) ?: [];

    foreach ($snapshots as $path) {
        preg_match('~/([a-z0-9_]+)\.(input|output)\.json$~', $path, $match);
        [, $name, $side] = $match;
        $dto = contractDto($name, $side);

        expect($dto)->not->toBeNull('No DTO app/Capabilities/*/Data/'.Str::studly($name).Str::studly($side).'.php');
        expect(contractJson($path))->toEqual(json_decode((string) json_encode($dto::jsonSchema()), true), basename($path));
    }

    expect($snapshots)->not->toBeEmpty();
});

it('CAP-015 makes every screen fixture state fit its read\'s output schema', function () {
    $fixtures = glob(base_path('tests/fixtures/screens/*.json')) ?: [];

    foreach ($fixtures as $path) {
        $name = explode('.', basename($path))[0];
        $schema = base_path("tests/fixtures/schemas/{$name}.output.json");

        expect(is_file($schema))->toBeTrue("{$name} has a fixture but no output schema snapshot");
        expect(SchemaCheck::problems(contractJson($schema), contractJson($path)))->toBe([], basename($path));
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
        $walk(contractJson($path));
    }

    expect($found)->toBeGreaterThan(0);
});
