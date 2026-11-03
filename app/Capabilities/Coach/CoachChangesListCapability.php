<?php

namespace App\Capabilities\Coach;

use App\Actions\Coach\ListCoachChanges;
use App\Capabilities\Coach\Data\CoachChangesListInput;
use App\Capabilities\Coach\Data\CoachChangesListOutput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/** The change cards under a Coach reply (AGT-013). The agent cannot call it (CAP-031). */
final class CoachChangesListCapability implements HostCapability
{
    public function __construct(private readonly ListCoachChanges $list) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('coach_changes_list')
            ->description('List the changes one of the signed-in user\'s Coach turns made, oldest first, each with its title, the tab it shows on and its undo token while it can still be undone. It changes nothing.')
            ->cli('coach', 'changes')
            ->input(CoachChangesListInput::class)
            ->output(CoachChangesListOutput::class)
            ->run(function (CoachChangesListInput $input, CapabilityContext $context): CoachChangesListOutput {
                /** @var User $user */
                $user = $context->user();

                return new CoachChangesListOutput(changes: ($this->list)($user, $input->turn));
            });
    }
}
