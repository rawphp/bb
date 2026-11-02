<?php

namespace App\Capabilities\WeighIns;

use App\Actions\WeighIns\UpdateWeighIn;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\WeighIns\Data\WeighInUpdateInput;
use App\Models\User;
use App\Models\WeighIn;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WeighInUpdateCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly UpdateWeighIn $update) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('weigh_in_update', affects: WeighInReads::AFFECTS)
            ->description('Correct one of the user\'s weigh-ins: its time, weight and waist, sent whole in the user\'s units. Leaving out the waist removes it. The trend weight and Today update from the change.')
            ->cli('weigh-in', 'update')
            ->input(WeighInUpdateInput::class)
            ->run(function (WeighInUpdateInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $days = ($this->update)($user, $input->id, $input->weighed_at, $input->weight, $input->waist);

                return new WriteResult(invalidates: WeighInReads::keys($days));
            });
    }

    public function owned(User $user): array
    {
        return ['id' => WeighIn::query()->where('user_id', $user->id)];
    }
}
