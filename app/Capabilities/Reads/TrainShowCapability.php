<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\TrainShowInput;
use App\Capabilities\Mesocycles\Data\TrainShowOutput;
use App\Capabilities\Reads\Train\TrainPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TrainShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('train_show')
            ->description('Show the Train tab: today\'s session, the running mesocycle with the prompt to build the next one, the unfinished draft, every mesocycle grouped current, planned and past, and the ways to create one. Use it to see where the user\'s training stands or which mesocycle to open. It changes nothing.')
            ->cli('train', 'show')
            ->input(TrainShowInput::class)
            ->output(TrainShowOutput::class)
            ->run(function (TrainShowInput $input, CapabilityContext $context): TrainShowOutput {
                /** @var User $user */
                $user = $context->user();

                return TrainPayload::build(TrainSnapshot::load($user));
            });
    }
}
