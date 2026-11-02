<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * CAP-020 step 5: the agent policy (AGT-010). Bound to {@see NoAgentPolicy}, which
 * refuses every destructive capability to the agent, until the coach (COACH_CTX)
 * binds its own, which allows one the user has confirmed in chat.
 */
interface AgentPolicy
{
    public function allows(CapabilityDefinition $capability, mixed $input, CapabilityContext $context): bool;
}
