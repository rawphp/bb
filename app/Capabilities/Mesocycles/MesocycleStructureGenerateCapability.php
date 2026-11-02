<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\GenerateMesocycleStructure;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleStructureGenerateInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleStructureGenerateCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly GenerateMesocycleStructure $generate) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('mesocycle_structure_generate', affects: ['train_show', 'mesocycle_show'], reversible: true)
            ->description('Lay out the days of a Meso Builder or from-scratch build from its days per week: the builder puts each muscle on its days at 2 sets a slot with no exercise yet, from scratch makes empty days. Use it when the user continues past the builder or scratch step. It replaces the build\'s days and works only on a build that is not saved.')
            ->cli('mesocycle', 'structure-generate')
            ->input(MesocycleStructureGenerateInput::class)
            ->run(function (MesocycleStructureGenerateInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                ($this->generate)($user, $input->mesocycle_id);

                return new WriteResult(invalidates: MesocycleReads::keys($input->mesocycle_id));
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::draftsOf($user)];
    }
}
