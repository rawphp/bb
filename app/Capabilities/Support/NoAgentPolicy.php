<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Support\CapabilityContext;

/** No agent policy yet: every call is allowed. */
final class NoAgentPolicy implements AgentPolicy
{
    public function allows(CapabilityDefinition $capability, mixed $input, CapabilityContext $context): bool
    {
        return true;
    }
}
