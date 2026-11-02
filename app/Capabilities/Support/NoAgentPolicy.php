<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * The agent policy until the coach (COACH_CTX) binds its own. No turn can carry the
 * user's confirmation yet, so the agent may not call a destructive capability (AGT-010);
 * every other call, and every call from another channel, is allowed.
 */
final class NoAgentPolicy implements AgentPolicy
{
    public function allows(CapabilityDefinition $capability, mixed $input, CapabilityContext $context): bool
    {
        return $context->caller() !== 'agent' || ! HostDefinition::isDestructive($capability);
    }
}
