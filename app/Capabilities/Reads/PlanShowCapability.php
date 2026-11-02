<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PlanShowInput;
use App\Capabilities\Plan\Data\PlanShowOutput;
use App\Capabilities\Reads\Plan\PlanPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PlanShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('plan_show')
            ->description('Show the user\'s plan: the timeline of phases with their dates and rates, the mesocycles under them, each deload week and whether it runs at maintenance calories, today\'s place, the current phase, any event countdown and the Maintenance suggestion. Use it before changing a phase or a deload\'s diet break. It changes nothing.')
            ->cli('plan', 'show')
            ->input(PlanShowInput::class)
            ->output(PlanShowOutput::class)
            ->run(function (PlanShowInput $input, CapabilityContext $context): PlanShowOutput {
                /** @var User $user */
                $user = $context->user();

                return PlanPayload::build(PlanSnapshot::load($user));
            });
    }
}
