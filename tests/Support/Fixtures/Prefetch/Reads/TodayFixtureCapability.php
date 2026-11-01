<?php

namespace Tests\Support\Fixtures\Prefetch\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\PrefetchesKeys;
use App\Models\User;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

final class TodayFixtureCapability implements HostCapability, PrefetchesKeys
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return Capability::define('fixture_today_show')
            ->description('A test fixture. It is never registered in the app. It changes nothing.')
            ->readOnly()
            ->run(fn () => []);
    }

    public function prefetchKeys(User $user): array
    {
        // Keyed by the user, so a test can tell whose manifest it got.
        return ['today_show{"date":"2026-11-02","user":'.$user->id.'}', 'mesocycle_show{}'];
    }
}
