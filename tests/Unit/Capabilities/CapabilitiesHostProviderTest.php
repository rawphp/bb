<?php

use App\Capabilities\HostAuthorizer;
use App\Providers\CapabilitiesHostProvider;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

it('CAP-003 registers every app/Capabilities/*/*Capability.php class', function () {
    $files = glob(app_path('Capabilities/*/*Capability.php'));

    expect(app(CapabilityRegistry::class)->all())
        ->toHaveCount(count($files))
        ->toHaveKey('account_show');
});

it('CAP-003 fails on a duplicate capability name', function () {
    CapabilitiesHostProvider::registerFrom(
        base_path('tests/Fixtures/DuplicateCapabilities'),
        'Tests\\Fixtures\\DuplicateCapabilities',
        new CapabilityRegistry,
        app(),
    );
})->throws(InvalidArgumentException::class, 'Duplicate capability name "fixture_show"');

it('CAP-020 binds the host authorizer', function () {
    expect(app(Authorizer::class))->toBeInstanceOf(HostAuthorizer::class);
});
