<?php

use App\Agent\Exclusions;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Contracts\ToolCatalog;
use Tests\Support\CapabilityParity;

it('CAP-030 AGT-001 lets the agent call every capability the Vue app calls, except the CAP-031 exclusions', function () {
    $ui = CapabilityParity::calledInApp(resource_path('js'));
    $snapshots = array_unique(array_map(
        fn (string $path): string => (string) preg_replace('/\.(input|output)\.json$/', '', basename($path)),
        glob(base_path('tests/fixtures/schemas/*.{input,output}.json'), GLOB_BRACE) ?: [],
    ));

    expect($ui)->not->toBe([])
        ->and(CapabilityParity::problems(
            ui: $ui,
            registered: array_keys(app(CapabilityRegistry::class)->all()),
            tools: array_column(app(ToolCatalog::class)->toolsForTurn('PARITY', 'PARITY'), 'name'),
            snapshots: array_values($snapshots),
            excluded: Exclusions::reason(...),
        ))->toBe([]);
});

it('CAP-031 says why for every entry on the exclusion list', function () {
    foreach ([...Exclusions::NAMES, ...Exclusions::PREFIXES] as $entry => $reason) {
        expect(trim($reason))->not->toBe('', "{$entry} has no reason");
    }
});
