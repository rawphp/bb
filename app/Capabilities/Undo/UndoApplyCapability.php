<?php

namespace App\Capabilities\Undo;

use App\Actions\Undo\ApplyUndo;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Undo\Data\UndoApplyInput;
use App\Models\User;
use LogicException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/**
 * Reverts a reversible write and every automatic change it made (CAP-013).
 * The toast's Undo (UI-010) and the agent's "undo that" both call it.
 */
final class UndoApplyCapability implements HostCapability
{
    public function __construct(private readonly ApplyUndo $undo) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('undo_apply', affects: [HostDefinition::ANY_READ])
            ->description('Undo a change using the undo token its write returned, together with every automatic change it made. It is refused once the token is 24 hours old, or when something has changed the same data since.')
            ->cli('undo', 'apply')
            ->input(UndoApplyInput::class)
            ->run(function (UndoApplyInput $input, CapabilityContext $context): WriteResult {
                // HostAuthorizer lets only a signed-in user reach run(), on every surface.
                $user = $context->user();
                if (! $user instanceof User) {
                    throw new LogicException('undo_apply needs the user who holds the undo token.');
                }

                return ($this->undo)($user, $input->token);
            });
    }
}
