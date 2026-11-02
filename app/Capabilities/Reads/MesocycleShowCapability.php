<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleShowInput;
use App\Capabilities\Mesocycles\Data\MesocycleShowOutput;
use App\Capabilities\Reads\Train\MesocyclePayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('mesocycle_show')
            ->description('Show one mesocycle: for a saved one its weeks, each day\'s exercises, sets per muscle, calories, diet breaks, priorities and what can be changed; for a draft the create flow\'s state. Use it before changing a mesocycle\'s length, days or end. It changes nothing.')
            ->cli('mesocycle', 'show')
            ->input(MesocycleShowInput::class)
            ->output(MesocycleShowOutput::class)
            ->run(function (MesocycleShowInput $input, CapabilityContext $context): MesocycleShowOutput {
                /** @var User $user */
                $user = $context->user();
                $snapshot = TrainSnapshot::load($user, $input->mesocycle_id);

                return MesocyclePayload::build($snapshot, $snapshot->mesocycle($input->mesocycle_id));
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::query()->where('user_id', $user->id)];
    }
}
