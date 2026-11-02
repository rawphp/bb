<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\SetMusclePriority;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\PrioritySetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PrioritySetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetMusclePriority $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('priority_set', affects: ['train_show', 'mesocycle_show'], reversible: true)
            ->description('Set one muscle\'s priority, Emphasize, Grow or Maintain, on a mesocycle build or a running mesocycle; on a running one a null priority sets the muscle Off when injury or pain stops the user training it. Use it from the Meso Builder or Progression rates. On a running mesocycle the change applies from the next session, and an Off muscle\'s exercises are left out of every session after it.')
            ->cli('mesocycle', 'priority-set')
            ->input(PrioritySetInput::class)
            ->run(function (PrioritySetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $muscle = MuscleGroup::from($input->muscle);
                $priority = $input->priority === null ? null : Priority::from($input->priority);
                $mesocycle = ($this->set)($user, $input->mesocycle_id, $muscle, $priority);
                $title = $priority?->title() ?? 'Off';

                return new WriteResult(
                    invalidates: MesocycleReads::keys($input->mesocycle_id),
                    toast: $mesocycle->isSaved() ? "{$muscle->title()} is {$title} from the next session" : null,
                );
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::query()->where('user_id', $user->id)];
    }
}
