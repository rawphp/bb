<?php

namespace App\Capabilities\Reads;

use App\Agent\AgentStatus;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\AgentStatusShowInput;
use App\Capabilities\Reads\Data\AgentStatusShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class AgentStatusShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('agent_status_show')
            ->description('Show whether the Coach is on for this instance and for the signed-in user, their conversation, and how much Coach use the signed-in user has left today and this month. Use it before offering the Coach or to explain a usage limit. It changes nothing.')
            ->cli('agent', 'status')
            ->input(AgentStatusShowInput::class)
            ->output(AgentStatusShowOutput::class)
            ->run(fn (AgentStatusShowInput $input, CapabilityContext $context): AgentStatusShowOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): AgentStatusShowOutput
    {
        /** @var User $user */
        $user = $context->user();
        // Resolved per call: the definition is built once at boot, the config can change after.
        $status = app(AgentStatus::class);
        $allowance = $status->allowance($user);

        return new AgentStatusShowOutput(
            enabled: $status->enabled(),
            on: $user->coach_on,
            conversation_ulid: $status->conversationUlid($user->getAuthIdentifier()),
            turns_left: $allowance->turnsLeft,
            tokens_left: $allowance->tokensLeft,
            at_cap: $allowance->atCap,
            resets_at: $allowance->resetsAt?->toIso8601String(),
            notice: $allowance->notice,
            // A reached cap is a warning; how much is left near it is information.
            notice_status: $allowance->notice === null ? null : new Status($allowance->notice, ($allowance->atCap ? Tone::Warning : Tone::Info)->value),
        );
    }
}
