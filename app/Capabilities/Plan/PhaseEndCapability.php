<?php

namespace App\Capabilities\Plan;

use App\Actions\Plan\EndPhase;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PhaseEndInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Phase;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PhaseEndCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly EndPhase $endPhase) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('phase_end', affects: PlanReads::AFFECTS, destructive: true)
            ->description('End the phase running now early: it ends at the next plan-week boundary and the phases after it move up; when it is the plan\'s last phase, plan_show then offers its transition and phase_transition_complete saves what comes next, while a phase with phases after it opens none because what comes next is already planned. Use it only when the user asks to stop the current phase; only the running phase can be ended. It cannot be undone, so confirm with the user first.')
            ->cli('phase', 'end')
            ->input(PhaseEndInput::class)
            ->run(function (PhaseEndInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $changed = ($this->endPhase)($user, $input->phase_id);

                return new WriteResult(invalidates: PlanReads::keys($user, ...$changed), toast: 'Phase ends this week');
            });
    }

    public function owned(User $user): array
    {
        return ['phase_id' => Phase::query()->where('user_id', $user->id)];
    }
}
