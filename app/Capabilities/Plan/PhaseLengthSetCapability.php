<?php

namespace App\Capabilities\Plan;

use App\Actions\Plan\SetPhaseLength;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PhaseLengthSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\PlanWords;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PhaseLengthSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetPhaseLength $setLength) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('phase_length_set', affects: PlanReads::AFFECTS)
            ->description('Lengthen or shorten a phase to one of the lengths phase_show lists, and the phases after it move with its end. Use it when the user wants a longer or shorter phase or to end one in its event week; fix says how to settle an end mid-mesocycle, as phase_show offers. A Maintenance phase with nothing after it may have no end.')
            ->cli('phase', 'length-set')
            ->input(PhaseLengthSetInput::class)
            ->run(function (PhaseLengthSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $changed = ($this->setLength)($user, $input->phase_id, $input->weeks, $input->fix);

                return new WriteResult(
                    invalidates: PlanReads::keys($user, ...$changed),
                    toast: $input->weeks === null ? 'Phase now has no end' : 'Phase is now '.PlanWords::weeks($input->weeks),
                );
            });
    }

    public function owned(User $user): array
    {
        return ['phase_id' => Phase::query()->where('user_id', $user->id)];
    }
}
