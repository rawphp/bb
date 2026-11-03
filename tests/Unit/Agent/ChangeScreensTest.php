<?php

use App\Agent\ChangeScreens;
use App\Agent\RegistryToolCatalog;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

it('AGT-013 maps every write the agent can call to a tab root or null', function () {
    $registry = app(CapabilityRegistry::class);
    $writes = array_values(array_filter(
        app(RegistryToolCatalog::class)->names(),
        fn (string $name): bool => ! $registry->get($name)->readOnly,
    ));

    expect($writes)->not->toBe([]);
    foreach ($writes as $name) {
        expect(ChangeScreens::SCREENS)->toHaveKey($name, "{$name} has no screen in ChangeScreens::SCREENS");
    }

    foreach (ChangeScreens::SCREENS as $name => $screen) {
        expect($screen)->toBeIn([...ChangeScreens::TABS, null], "{$name} maps to {$screen}");
    }
});

it('AGT-013 gives a capability missing from the map no screen', function () {
    expect(ChangeScreens::for('other_activity_save'))->toBe('/today')
        ->and(ChangeScreens::for('not_a_capability'))->toBeNull();
});
