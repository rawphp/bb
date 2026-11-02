<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\FillMesocycleSlots;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleSlotsFillInput;
use App\Capabilities\Reads\Train\TrainWords;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleSlotsFillCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly FillMesocycleSlots $fill) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('mesocycle_slots_fill', affects: ['train_show', 'mesocycle_show'], reversible: true)
            ->description('Pick for me: fill every slot of a build that has no exercise with the best stimulus-to-fatigue exercise for its muscle, compounds first. Use it when the user wants the app to choose the exercises they have not picked. Slots that already have an exercise keep it, and it works only on a build that is not saved.')
            ->cli('mesocycle', 'slots-fill')
            ->input(MesocycleSlotsFillInput::class)
            ->run(function (MesocycleSlotsFillInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $filled = ($this->fill)($user, $input->mesocycle_id);

                return new WriteResult(
                    invalidates: MesocycleReads::keys($input->mesocycle_id),
                    toast: $filled === 0 ? 'No empty slots to fill' : 'Picked '.TrainWords::count($filled, 'exercise'),
                );
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::draftsOf($user)];
    }
}
