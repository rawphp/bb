<?php

namespace App\Capabilities;

use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

/**
 * One capability per class, in app/Capabilities/<Module>/<StudlyName>Capability.php.
 * CapabilitiesHostProvider loads each one and registers the definition it returns.
 */
interface HostCapability
{
    public function definition(): CapabilityDefinitionBuilder;
}
