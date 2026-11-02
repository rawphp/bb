<?php

namespace App\Capabilities\Today;

use App\Actions\Today\OptimizeDay;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\DayOptimizeShowInput;
use App\Capabilities\Reads\Data\DayOptimizeShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class DayOptimizeShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('day_optimize_show')
            ->description('Preview Optimize schedule for a day: each meal\'s time before and after its unchecked meals are placed again around the day\'s workout and eating window, or why the day cannot be optimized. Use it before day_optimize, which saves this result. It changes nothing.')
            ->cli('day', 'optimize-show')
            ->input(DayOptimizeShowInput::class)
            ->output(DayOptimizeShowOutput::class)
            ->run(function (DayOptimizeShowInput $input, CapabilityContext $context): DayOptimizeShowOutput {
                /** @var User $user */
                $user = $context->user();

                return OptimizeDay::preview($user, $input->date);
            });
    }
}
