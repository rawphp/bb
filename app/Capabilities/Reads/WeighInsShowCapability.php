<?php

namespace App\Capabilities\WeighIns;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\WeighIns\Data\WeighInsShowInput;
use App\Capabilities\WeighIns\Data\WeighInsShowOutput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WeighInsShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('weigh_ins_show')
            ->description('Show the user\'s weigh-ins, newest first, with missed scheduled weigh-in days marked, the trend weight, the weigh-in schedule and the step counts typed in or posted by the Health bridge. Use it to find a weigh-in to edit or delete, or to check the schedule. It changes nothing.')
            ->cli('weigh-in', 'list')
            ->input(WeighInsShowInput::class)
            ->output(WeighInsShowOutput::class)
            ->run(function (WeighInsShowInput $input, CapabilityContext $context): WeighInsShowOutput {
                /** @var User $user */
                $user = $context->user();

                return WeighInsPayload::build($user, CarbonImmutable::now());
            });
    }
}
