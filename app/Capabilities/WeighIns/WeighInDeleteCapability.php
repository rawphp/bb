<?php

namespace App\Capabilities\WeighIns;

use App\Actions\WeighIns\DeleteWeighIn;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\WeighIns\Data\WeighInDeleteInput;
use App\Models\User;
use App\Models\WeighIn;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class WeighInDeleteCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly DeleteWeighIn $delete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: it deletes, so the coach needs the user's say-so.
        return HostDefinition::write('weigh_in_delete', affects: WeighInReads::WEIGH_IN_AFFECTS, destructive: true)
            ->description('Delete one of the user\'s weigh-ins, such as a reading logged by mistake. The trend weight is worked out again without it. It cannot be undone.')
            ->cli('weigh-in', 'delete')
            ->input(WeighInDeleteInput::class)
            ->run(function (WeighInDeleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: WeighInReads::weighInKeys($user, [($this->delete)($user, $input->id)]));
            });
    }

    public function owned(User $user): array
    {
        return ['id' => WeighIn::query()->where('user_id', $user->id)];
    }
}
