<?php

namespace App\Capabilities\Coach;

use App\Actions\Coach\ClearCoachConversation;
use App\Capabilities\Coach\Data\CoachConversationClearInput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/** Clear the user's Coach conversation (AGT-020, AGT-024). No undo. The agent cannot call it (CAP-031). */
final class CoachConversationClearCapability implements HostCapability
{
    public function __construct(private readonly ClearCoachConversation $clear) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('coach_conversation_clear', affects: ['agent_status_show'])
            ->description('Delete the signed-in user\'s Coach conversation, with every message and turn in it. It cannot be undone, and it is refused while the Coach is still answering.')
            ->cli('coach', 'conversation-clear')
            ->input(CoachConversationClearInput::class)
            ->run(function (CoachConversationClearInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                ($this->clear)($user);

                return new WriteResult(invalidates: [ReadKey::for('agent_status_show')], toast: 'Conversation cleared.');
            });
    }
}
