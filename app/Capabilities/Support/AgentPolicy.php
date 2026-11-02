<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * CAP-020 step 5: the agent policy (AGT-010). Bound to {@see NoAgentPolicy}
 * until the coach (COACH_CTX) binds its own, which refuses a destructive call
 * the user has not confirmed in chat.
 */
interface AgentPolicy
{
    public function allows(CapabilityDefinition $capability, mixed $input, CapabilityContext $context): bool;
}
