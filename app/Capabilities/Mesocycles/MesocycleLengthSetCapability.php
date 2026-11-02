<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\SetMesocycleLength;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleLengthSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleLengthSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetMesocycleLength $setLength) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('mesocycle_length_set', affects: ['train_show', 'mesocycle_show'])
            ->description('Change how many weeks a running mesocycle lasts, the deload included; the deload stays the last week. Use it when the user wants a longer or shorter block, within the lengths mesocycle_show allows. It changes only the length, and never during the deload week.')
            ->cli('mesocycle', 'length-set')
            ->input(MesocycleLengthSetInput::class)
            ->run(function (MesocycleLengthSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->setLength)($user, $input->mesocycle_id, $input->weeks);

                return new WriteResult(invalidates: MesocycleReads::keys($input->mesocycle_id), toast: "Mesocycle is now {$input->weeks} weeks");
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::query()->where('user_id', $user->id)->where('is_draft', false)];
    }
}
