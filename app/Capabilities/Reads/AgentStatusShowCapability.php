<?php

namespace App\Capabilities\Reads;

use App\Agent\AgentStatus;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\AgentStatusShowInput;
use App\Capabilities\Reads\Data\AgentStatusShowOutput;
use App\Capabilities\Support\HostDefinition;
use Illuminate\Contracts\Auth\Authenticatable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class AgentStatusShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('agent_status_show')
            ->description('Show whether the Coach is on for this instance and how much Coach use the signed-in user has left today and this month. Use it before offering the Coach or to explain a usage limit. It changes nothing.')
            ->cli('agent', 'status')
            ->input(AgentStatusShowInput::class)
            ->output(AgentStatusShowOutput::class)
            ->run(fn (AgentStatusShowInput $input, CapabilityContext $context): AgentStatusShowOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): AgentStatusShowOutput
    {
        /** @var Authenticatable $user */
        $user = $context->user();
        // Resolved per call: the definition is built once at boot, the config can change after.
        $status = app(AgentStatus::class);
        $allowance = $status->allowance($user->getAuthIdentifier());

        return new AgentStatusShowOutput(
            enabled: $status->enabled(),
            turns_left: $allowance->turnsLeft,
            tokens_left: $allowance->tokensLeft,
            at_cap: $allowance->atCap,
            resets_at: $allowance->resetsAt?->toIso8601String(),
            notice: $allowance->notice,
        );
    }
}
