<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\TodayShowInput;
use App\Capabilities\Reads\Data\TodayShowOutput;
use App\Capabilities\Reads\Today\TodayPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use App\Support\Today\TodaySnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TodayShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('today_show')
            ->description('Show one day of the plan, today unless a date is given: its meals with their targets, planned foods and check-ins, the workout, the weigh-in, busy periods, other activities and off-plan food in time order, the day\'s calorie and protein totals, the step target and the prompt to build the next mesocycle. Use it to see what the user eats and trains on a day. It changes nothing.')
            ->cli('today', 'show')
            ->input(TodayShowInput::class)
            ->output(TodayShowOutput::class)
            ->run(function (TodayShowInput $input, CapabilityContext $context): TodayShowOutput {
                /** @var User $user */
                $user = $context->user();

                return TodayPayload::build(TodaySnapshot::load($user, $input->date));
            });
    }
}
