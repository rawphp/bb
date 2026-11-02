<?php

use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// SchemaSnapshotTest keeps each snapshot equal to the live schema; this makes
// sure no registered capability is missing one. Every write returns the same
// WriteResult, so a write's output snapshot is needed only when it returns
// something else.
it('CAP-032 has a schema snapshot for every registered capability', function () {
    $missing = [];

    foreach (app(CapabilityRegistry::class)->all() as $name => $definition) {
        $sides = ['input' => $definition->inputSchema() !== null];
        $sides['output'] = $definition->outputSchema() !== null && $definition->output !== WriteResult::class;

        foreach (array_keys(array_filter($sides)) as $side) {
            if (! is_file(base_path("tests/fixtures/schemas/{$name}.{$side}.json"))) {
                $missing[] = "{$name}.{$side}.json";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('CAP-032 registers every capability on the surfaces it declares: http, job, mcp, cli and agent', function () {
    $registry = app(CapabilityRegistry::class);
    $enabled = $registry->globallyEnabledSurfaces();

    expect($registry->all())->not->toBe([]);

    foreach ($registry->all() as $name => $definition) {
        expect($definition->surfaces)->toEqualCanonicalizing(HostDefinition::SURFACES, "{$name} declares other surfaces")
            ->and($definition->effectiveSurfaces($enabled))->toEqualCanonicalizing($definition->surfaces, "{$name} is not open on every surface it declares");
    }
});
