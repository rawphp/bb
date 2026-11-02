<?php

use App\Capabilities\HostAuthorizer;
use App\Capabilities\Support\BillingOnly;
use App\Providers\CapabilitiesHostProvider;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

it('CAP-003 registers every app/Capabilities/*/*Capability.php class, but billing\'s only with billing on (SUB-020)', function () {
    $files = array_filter(
        glob(app_path('Capabilities/*/*Capability.php')),
        fn (string $file) => config('billing.enabled') || ! is_subclass_of('App\\Capabilities\\'.basename(dirname($file)).'\\'.basename($file, '.php'), BillingOnly::class),
    );

    expect(app(CapabilityRegistry::class)->all())
        ->toHaveCount(count($files))
        ->toHaveKey('account_show');
});

it('CAP-003 fails on a duplicate capability name', function () {
    CapabilitiesHostProvider::registerFrom(
        base_path('tests/Support/Fixtures/DuplicateCapabilities'),
        'Tests\\Support\\Fixtures\\DuplicateCapabilities',
        new CapabilityRegistry,
        app(),
    );
})->throws(InvalidArgumentException::class, 'Duplicate capability name "fixture_show"');

it('CAP-020 binds the host authorizer', function () {
    expect(app(Authorizer::class))->toBeInstanceOf(HostAuthorizer::class);
});
