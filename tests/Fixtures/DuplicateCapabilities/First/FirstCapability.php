<?php

namespace Tests\Fixtures\DuplicateCapabilities\First;

use App\Capabilities\HostCapability;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

final class FirstCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return Capability::define('fixture_show')
            ->description('A test fixture. It is never registered in the app. It changes nothing.')
            ->readOnly()
            ->run(fn () => []);
    }
}
