<?php

namespace App\Capabilities\WeighIns;

use App\Actions\WeighIns\LogSteps;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\WeighIns\Data\StepsLogInput;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class StepsLogCapability implements HostCapability
{
    public function __construct(private readonly LogSteps $log) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('steps_log', affects: WeighInReads::AFFECTS)
            ->description('Set the step count the user reported for a day, usually yesterday, since today\'s count is not final until the day ends. A day has one count, and logging it again replaces it. A count from the Health bridge never replaces one the user typed.')
            ->cli('steps', 'log')
            ->input(StepsLogInput::class)
            ->run(function (StepsLogInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $changed = ($this->log)($user, $input->date, $input->steps, MeasuredBy::caller($context));

                return new WriteResult(invalidates: $changed ? WeighInReads::keys([$input->date]) : []);
            });
    }
}
