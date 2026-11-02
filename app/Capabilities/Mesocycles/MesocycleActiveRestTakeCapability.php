<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\TakeActiveRest;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleActiveRestTakeInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleActiveRestTakeCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly TakeActiveRest $take) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('mesocycle_active_rest_take', affects: ['train_show', 'mesocycle_show'])
            ->description('Take the active rest recommended after flat training weeks: the deload week plus a week off. Use it only when train_show\'s next-mesocycle prompt recommends it for this mesocycle. It moves the next mesocycle\'s start one week later.')
            ->cli('mesocycle', 'active-rest-take')
            ->input(MesocycleActiveRestTakeInput::class)
            ->run(function (MesocycleActiveRestTakeInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->take)($user, $input->mesocycle_id);

                return new WriteResult(invalidates: MesocycleReads::keys($input->mesocycle_id), toast: 'Active rest planned');
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::query()->where('user_id', $user->id)->where('is_draft', false)];
    }
}
