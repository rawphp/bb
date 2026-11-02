<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Progress\Data\ProgressShowInput;
use App\Capabilities\Progress\Data\ProgressShowOutput;
use App\Capabilities\Reads\Progress\ProgressPayload;
use App\Capabilities\Support\HostDefinition;
use App\Domain\Progress\ProgressScope;
use App\Models\User;
use App\Support\Progress\ProgressSnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ProgressShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('progress_show')
            ->description('Show the Progress tab: the weight chart and its summary for the current phase or the whole plan, with the trend weight, the goal range and the waist line; the share text; this week\'s calories against target with each day\'s adherence; whether the weekly review is available; today\'s steps against the step target; past phases and past mesocycles; and the exercises with a logged set, most recently used first, with an empty state in place of the chart and summary until there are 4 weigh-ins. Use it to see how the user is progressing. It changes nothing.')
            ->cli('progress', 'show')
            ->input(ProgressShowInput::class)
            ->output(ProgressShowOutput::class)
            ->run(function (ProgressShowInput $input, CapabilityContext $context): ProgressShowOutput {
                /** @var User $user */
                $user = $context->user();

                return ProgressPayload::build(
                    ProgressSnapshot::load($user),
                    $input->scope === 'plan' ? ProgressScope::WholePlan : ProgressScope::CurrentPhase,
                );
            });
    }
}
