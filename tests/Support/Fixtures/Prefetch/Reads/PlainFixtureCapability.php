<?php

namespace Tests\Support\Fixtures\Prefetch\Reads;

use App\Capabilities\HostCapability;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

final class PlainFixtureCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return Capability::define('fixture_plain_show')
            ->description('A test fixture. It is never registered in the app. It changes nothing.')
            ->readOnly()
            ->run(fn () => []);
    }
}
