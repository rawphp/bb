<?php

namespace App\Capabilities\Mesocycles;

use App\Actions\Mesocycles\BuildMesocycle;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\MesocycleBuildInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\Template;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MesocycleBuildCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly BuildMesocycle $build) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: a new build deletes the user's other unfinished one (MESO-007), so the coach needs the user's say-so.
        return HostDefinition::write('mesocycle_build', affects: ['train_show', 'mesocycle_show'], destructive: true)
            ->description('Start or update a mesocycle build: its route, a template to start from, name, length, start date, day labels, days per week and the days with their muscle slots, sets and exercises; finish saves it as a mesocycle. Use it at each step of the create flow; mesocycle_show says what each field may be. A new mesocycle_id deletes any other unfinished build, which cannot be undone, and a field left out keeps its value.')
            ->cli('mesocycle', 'build')
            ->input(MesocycleBuildInput::class)
            ->run(function (MesocycleBuildInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $existing = $this->ownedOrNew(Mesocycle::class, $input->mesocycle_id, $user);
                $replaced = ($this->build)($user, $existing, $input);
                $name = Mesocycle::query()->where('user_id', $user->id)->whereKey($input->mesocycle_id)->value('name');

                return new WriteResult(
                    invalidates: MesocycleReads::keys($input->mesocycle_id, ...$replaced),
                    toast: $input->finish ? "{$name} saved" : null,
                );
            });
    }

    /** The library's templates are everyone's; an exercise is the library's or the user's own (CAP-020). */
    public function owned(User $user): array
    {
        return [
            'template_id' => Template::query(),
            'days.*.slots.*.exercise_id' => Exercise::query()->visibleTo($user),
        ];
    }
}
