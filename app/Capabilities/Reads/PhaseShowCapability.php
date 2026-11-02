<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PhaseShowInput;
use App\Capabilities\Plan\Data\PhaseShowOutput;
use App\Capabilities\Reads\Plan\PhasePayload;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Models\Phase;
use App\Models\User;
use App\Support\Plan\PlanSnapshot;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PhaseShowCapability implements HostCapability, OwnsInput
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('phase_show')
            ->description('Show one phase of the plan: its type, dates, rate, progress, start, target and current weight, its event date, and what it can change to: the lengths it may take with how to settle an end mid-mesocycle, and each other goal with its training defaults and warnings. Use it before phase_length_set, phase_goal_change or phase_end. It changes nothing.')
            ->cli('phase', 'show')
            ->input(PhaseShowInput::class)
            ->output(PhaseShowOutput::class)
            ->run(function (PhaseShowInput $input, CapabilityContext $context): PhaseShowOutput {
                /** @var User $user */
                $user = $context->user();
                $plan = PlanSnapshot::load($user);
                $phase = $plan?->findPhase($input->phase_id);
                abort_if($plan === null || $phase === null, 404);

                return PhasePayload::build($plan, $phase);
            });
    }

    public function owned(User $user): array
    {
        return ['phase_id' => Phase::query()->where('user_id', $user->id)];
    }
}
