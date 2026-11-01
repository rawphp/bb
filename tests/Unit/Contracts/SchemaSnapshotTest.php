<?php

use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\SchemaSnapshot;

// Snapshots in tests/fixtures/schemas/ feed `npm run contracts`. Once a capability
// is registered, its snapshot must stay equal to the live schema.
it('CAP-032 keeps each registered capability\'s schema snapshot equal to its live schema', function () {
    $registry = app(CapabilityRegistry::class);
    $checked = 0;

    foreach (glob(base_path('tests/fixtures/schemas/*.{input,output}.json'), GLOB_BRACE) ?: [] as $path) {
        preg_match('~/([a-z0-9_]+)\.(input|output)\.json$~', $path, $match);
        [, $name, $side] = $match;

        if (! $registry->has($name)) {
            continue;
        }

        $definition = $registry->get($name);
        $live = $side === 'input' ? $definition->inputSchema() : $definition->outputSchema();
        $snapshot = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        expect(SchemaSnapshot::schemasEqual($snapshot, $live))->toBeTrue("{$name}.{$side}.json differs from the live schema");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});
