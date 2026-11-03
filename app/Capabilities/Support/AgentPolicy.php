<?php

namespace App\Capabilities\Support;

use App\Agent\ConfirmationPolicy;
use Rawphp\Capabilities\Registry\CapabilityDefinition;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * CAP-020 step 5: the agent policy (AGT-010). Bound to {@see ConfirmationPolicy},
 * which allows the agent a destructive capability only when the user confirmed that call in chat,
 * and refuses an MCP call (AGT-040) every destructive capability, because it has no turn to confirm it.
 */
interface AgentPolicy
{
    public function allows(CapabilityDefinition $capability, mixed $input, CapabilityContext $context): bool;
}
