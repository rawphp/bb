<?php

use App\Capabilities\Support\PrefetchesKeys;
use App\Models\User;
use App\Providers\CapabilitiesHostProvider;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityResult;
use Tests\Support\Fixtures\Prefetch\Reads\PlainFixtureCapability;
use Tests\Support\Fixtures\Prefetch\Reads\TodayFixtureCapability;
use Tests\Support\Fixtures\Prefetch\Reads\WeekFixtureCapability;

function registerPrefetchFixtures(): void
{
    CapabilitiesHostProvider::registerFrom(
        base_path('tests/Support/Fixtures/Prefetch'),
        'Tests\\Support\\Fixtures\\Prefetch',
        new CapabilityRegistry,
        app(),
    );
}

it('OFF-004 tags every loaded capability that implements PrefetchesKeys', function () {
    registerPrefetchFixtures();

    expect(array_map(get_class(...), iterator_to_array(app()->tagged(PrefetchesKeys::class), false)))
        ->toBe([TodayFixtureCapability::class, WeekFixtureCapability::class])
        ->not->toContain(PlainFixtureCapability::class);
});

it('OFF-004 sync_manifest returns the keys of every capability that implements PrefetchesKeys, each once', function () {
    registerPrefetchFixtures();

    $result = app(CapabilityRegistry::class)->invoke('sync_manifest', [], ['actor' => tap(new User, fn (User $u) => $u->id = 1)]);

    expect($result->isOk())->toBeTrue()
        ->and($result->data->toArray())->toBe(['keys' => ['today_show{"date":"2026-11-02"}', 'mesocycle_show{}', 'week_show{}']]);
});

it('OFF-004 sync_manifest answers the same on http, job, mcp and cli, with no keys while no read prefetches', function () {
    app(CapabilityRegistry::class)->assertParity('sync_manifest', [
        'surfaces' => ['http', 'job', 'mcp', 'cli'],
        'actor' => new User,
        'assert' => fn (CapabilityResult $result) => expect($result->data->toArray())->toBe(['keys' => []]),
    ]);
});
