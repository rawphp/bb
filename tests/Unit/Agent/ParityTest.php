<?php

use App\Agent\Exclusions;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\CapabilitiesAi\Contracts\ToolCatalog;
use Tests\Support\CapabilityParity;
use Tests\Support\ContractDtos;

it('CAP-030 AGT-001 lets the agent call every registered capability the Vue app calls, except the CAP-031 exclusions, and holds each unregistered one to an owner issue', function () {
    $ui = CapabilityParity::calledInApp(resource_path('js'));
    $schemas = base_path('tests/fixtures/schemas');

    expect($ui)->not->toBe([])
        ->and(CapabilityParity::problems(
            ui: $ui,
            registered: array_keys(app(CapabilityRegistry::class)->all()),
            tools: array_column(app(ToolCatalog::class)->toolsForTurn('PARITY', 'PARITY'), 'name'),
            snapshots: CapabilityParity::snapshotNames($schemas),
            owners: ContractDtos::owners(base_path()),
            excluded: Exclusions::reason(...),
        ))->toBe([]);
});

it('CAP-031 says why for every entry on the exclusion list', function () {
    foreach ([...Exclusions::NAMES, ...Exclusions::PREFIXES] as $entry => $reason) {
        expect(trim($reason))->not->toBe('', "{$entry} has no reason");
    }
});
