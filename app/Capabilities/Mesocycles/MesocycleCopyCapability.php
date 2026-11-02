<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\CopyMesocycle;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleCopyInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleCopyCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly CopyMesocycle $copy) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: the copy deletes the user's unfinished draft (MESO-007), so the coach needs the user's say-so.
        return HostDefinition::write('mesocycle_copy', affects: ['train_show', 'mesocycle_show'], destructive: true)
            ->description('Copy a saved mesocycle into a new draft with the same days, exercises and sets, each exercise starting from its old week-1 load. Use it to build the next mesocycle from the last one; without start_date it starts when the next mesocycle is due. It deletes any other unfinished draft, which cannot be undone, and in a phase of another type it takes that phase\'s priorities unless keep_priorities is set.')
            ->cli('mesocycle', 'copy')
            ->input(MesocycleCopyInput::class)
            ->run(function (MesocycleCopyInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $existing = $this->ownedOrNew(Mesocycle::class, $input->mesocycle_id, $user);
                $replaced = ($this->copy)($user, $existing, $input->mesocycle_id, $input->source_mesocycle_id, $input->start_date, $input->keep_priorities);

                return new WriteResult(
                    invalidates: MesocycleReads::keys($input->mesocycle_id, ...$replaced),
                    toast: 'Copied into a new draft',
                );
            });
    }

    public function owned(User $user): array
    {
        return ['source_mesocycle_id' => Mesocycle::savedBy($user)];
    }
}
