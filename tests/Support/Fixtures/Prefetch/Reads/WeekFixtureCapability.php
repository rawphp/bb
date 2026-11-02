<?php

namespace Tests\Support\Fixtures\Prefetch\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\PrefetchesKeys;
use App\Models\User;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

final class WeekFixtureCapability implements HostCapability, PrefetchesKeys
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return Capability::define('fixture_week_show')
            ->description('A test fixture. It is never registered in the app. It changes nothing.')
            ->readOnly()
            ->run(fn () => []);
    }

    public function prefetchKeys(User $user): array
    {
        return ['mesocycle_show{}', 'week_show{}'];
    }
}
