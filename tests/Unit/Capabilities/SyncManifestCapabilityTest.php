<?php

use App\Capabilities\Support\PrefetchesKeys;
use App\Capabilities\Sync\SyncManifestCapability;
use App\Models\User;
use App\Providers\CapabilitiesHostProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Rawphp\Capabilities\Contracts\Authorizer;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityResult;
use Tests\Support\Fixtures\Prefetch\Reads\PlainFixtureCapability;
use Tests\Support\Fixtures\Prefetch\Reads\TodayFixtureCapability;
use Tests\Support\Fixtures\Prefetch\Reads\WeekFixtureCapability;

// sync_manifest and the fixtures built and tagged on one container that is not app(),
// so a lookup on the global container would find no prefetchers.
function registerPrefetchFixtures(?Container &$container = null): CapabilityRegistry
{
    $container = new Container;
    $container->instance(ContainerContract::class, $container);
    $registry = new CapabilityRegistry(authorizer: app(Authorizer::class));
    $container->make(SyncManifestCapability::class)->definition()->register($registry);
    CapabilitiesHostProvider::registerFrom(
        base_path('tests/Support/Fixtures/Prefetch'),
        'Tests\\Support\\Fixtures\\Prefetch',
        $registry,
        $container,
    );

    return $registry;
}

it('OFF-004 tags every loaded capability that implements PrefetchesKeys', function () {
    registerPrefetchFixtures($container);

    expect(array_map(get_class(...), iterator_to_array($container->tagged(PrefetchesKeys::class), false)))
        ->toBe([TodayFixtureCapability::class, WeekFixtureCapability::class])
        ->not->toContain(PlainFixtureCapability::class);
});

it('OFF-004 sync_manifest returns the keys of every capability that implements PrefetchesKeys, each once', function () {
    $result = registerPrefetchFixtures()->invoke('sync_manifest', [], ['actor' => tap(new User, fn (User $u) => $u->id = 1)]);

    expect($result->isOk())->toBeTrue()
        ->and($result->data->toArray())->toBe(['keys' => ['today_show{"date":"2026-11-02","user":1}', 'mesocycle_show{}', 'week_show{}']]);
});

it('OFF-004 sync_manifest asks each prefetching read for the signed-in user\'s keys', function () {
    $registry = registerPrefetchFixtures();
    $manifest = fn (int $id) => $registry
        ->invoke('sync_manifest', [], ['actor' => tap(new User, fn (User $u) => $u->id = $id)])
        ->data->toArray()['keys'][0];

    expect($manifest(7))->toBe('today_show{"date":"2026-11-02","user":7}')
        ->and($manifest(8))->toBe('today_show{"date":"2026-11-02","user":8}');
});

it('OFF-004 sync_manifest answers the same on http, job, mcp and cli, with no keys while no read prefetches', function () {
    app(CapabilityRegistry::class)->assertParity('sync_manifest', [
        'surfaces' => ['http', 'job', 'mcp', 'cli'],
        'actor' => new User,
        'assert' => fn (CapabilityResult $result) => expect($result->data->toArray())->toBe(['keys' => []]),
    ]);
});

it('OFF-004 sync_manifest asks a prefetching read once even when it was registered twice', function () {
    $registry = registerPrefetchFixtures($container);
    CapabilitiesHostProvider::registerFrom(base_path('tests/Support/Fixtures/Prefetch'), 'Tests\\Support\\Fixtures\\Prefetch', new CapabilityRegistry, $container);
    TodayFixtureCapability::$asked = 0;

    $registry->invoke('sync_manifest', [], ['actor' => tap(new User, fn (User $u) => $u->id = 1)]);

    expect(TodayFixtureCapability::$asked)->toBe(1);
});
