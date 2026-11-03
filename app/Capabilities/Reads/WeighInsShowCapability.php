<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\WeighIns\WeighInsPayload;
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
            ->description('Show the user\'s weigh-ins, newest first, with missed scheduled weigh-in days marked, the trend weight, the weigh-in schedule and the step counts typed in or posted by the Health bridge. It returns 90 days at a time, newest first: when next_before is set, pass it as before to read the 90 days before. Use it to find a weigh-in to edit or delete, or to check the schedule. It changes nothing.')
            ->cli('weigh-in', 'list')
            ->input(WeighInsShowInput::class)
            ->output(WeighInsShowOutput::class)
            ->run(function (WeighInsShowInput $input, CapabilityContext $context): WeighInsShowOutput {
                /** @var User $user */
                $user = $context->user();

                return WeighInsPayload::build($user, CarbonImmutable::now(), $input->before);
            });
    }
}
