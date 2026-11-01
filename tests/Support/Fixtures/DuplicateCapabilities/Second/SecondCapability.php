<?php

namespace Tests\Support\Fixtures\DuplicateCapabilities\Second;

use App\Capabilities\HostCapability;
use Rawphp\Capabilities\Capability;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

final class SecondCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return Capability::define('fixture_show')
            ->description('A test fixture. It is never registered in the app. It changes nothing.')
            ->readOnly()
            ->run(fn () => []);
    }
}
