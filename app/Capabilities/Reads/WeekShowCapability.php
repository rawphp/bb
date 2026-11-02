<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\WeekShowInput;
use App\Capabilities\Reads\Data\WeekShowOutput;
use App\Capabilities\Reads\Today\WeekPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use App\Support\Today\TodaySnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WeekShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('week_show')
            ->description('Show the plan week around a day, today unless a date is given: each day\'s calorie target, which days were eaten on target, which days train, whether the week is a deload, and the dates the plan covers. Use it to see the shape of the user\'s week or to find a day to open with today_show. It changes nothing.')
            ->cli('week', 'show')
            ->input(WeekShowInput::class)
            ->output(WeekShowOutput::class)
            ->run(function (WeekShowInput $input, CapabilityContext $context): WeekShowOutput {
                /** @var User $user */
                $user = $context->user();

                return WeekPayload::build(TodaySnapshot::loadWeek($user, $input->date));
            });
    }
}
