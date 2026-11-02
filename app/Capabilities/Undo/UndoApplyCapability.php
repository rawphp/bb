<?php

namespace App\Capabilities\Undo;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\UndoLog;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Undo\Data\UndoApplyInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * Reverts a reversible write and every automatic change it made (CAP-013).
 * The toast's Undo (UI-010) and the agent's "undo that" both call it.
 */
final class UndoApplyCapability implements HostCapability
{
    public function __construct(private readonly UndoLog $undo) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('undo_apply', affects: [HostDefinition::ANY_READ])
            ->description('Undo a change using the undo token its write returned, together with every automatic change it made. It is refused once the token is 24 hours old, or when something has changed the same data since.')
            ->cli('undo', 'apply')
            ->input(UndoApplyInput::class)
            ->run(function (UndoApplyInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return $this->undo->apply($user, $input->token);
            });
    }
}
