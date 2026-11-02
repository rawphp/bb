<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\Data\TemplatesShowInput;
use App\Capabilities\Mesocycles\Data\TemplatesShowOutput;
use App\Capabilities\Reads\Train\TemplatesPayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\Template;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class TemplatesShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('templates_show')
            ->description('List the training templates, filtered by days per week, sex, whole body or emphasis, and the phase they fit, which starts on the phase in force; with template_id, also that template\'s days, exercises, muscle priorities and weekly sets. Use it to find a template before starting a build from it with mesocycle_build. It changes nothing.')
            ->cli('template', 'list')
            ->input(TemplatesShowInput::class)
            ->output(TemplatesShowOutput::class)
            ->run(function (TemplatesShowInput $input, CapabilityContext $context): TemplatesShowOutput {
                /** @var User $user */
                $user = $context->user();
                $snapshot = TrainSnapshot::load($user);

                return TemplatesPayload::build(
                    Template::query()->get(),
                    $input,
                    $snapshot->phaseCovering($snapshot->today)?->type,
                    $input->template_id === null ? null : Template::query()->with('slots.exercise')->findOrFail($input->template_id),
                );
            });
    }

    /** The library's templates are everyone's (CAP-020). */
    public function owned(User $user): array
    {
        return ['template_id' => Template::query()];
    }
}
