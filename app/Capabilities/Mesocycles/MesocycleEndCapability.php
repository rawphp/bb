<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\EndMesocycle;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleEndInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleEndCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly EndMesocycle $end) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: ending drops the rest of the mesocycle, the deload included, so the coach needs the user's say-so.
        return HostDefinition::write('mesocycle_end', affects: ['train_show', 'mesocycle_show'], destructive: true)
            ->description('End a running mesocycle today, dropping the weeks left, the deload included; it cannot be undone. Use it only when the user asks to stop the block early, after warning that skipping the deload is not recommended. In fat loss, diet_break also makes next week a diet break.')
            ->cli('mesocycle', 'end')
            ->input(MesocycleEndInput::class)
            ->run(function (MesocycleEndInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->end)($user, $input->mesocycle_id, $input->diet_break);

                return new WriteResult(
                    invalidates: MesocycleReads::keys($input->mesocycle_id),
                    toast: $input->diet_break ? 'Mesocycle ended. Next week is a diet break.' : 'Mesocycle ended',
                );
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::savedBy($user)];
    }
}
