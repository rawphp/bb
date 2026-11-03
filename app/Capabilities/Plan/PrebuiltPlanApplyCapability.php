<?php

namespace App\Capabilities\Plan;

use App\Actions\Plan\ApplyPrebuiltPlan;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PrebuiltPlanApplyInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Domain\Plan\PrebuiltPlan;
use App\Models\Phase;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PrebuiltPlanApplyCapability implements HostCapability
{
    use UsesClientUlid;

    public function __construct(private readonly ApplyPrebuiltPlan $apply) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('prebuilt_plan_apply', affects: PlanReads::AFFECTS, destructive: true)
            ->description('Add one of RP\'s two 24-week plans (muscleGain or fatLoss) to the end of the plan, as plan_show\'s prebuilt_plans lays it out: six months of phases at once. Use it only when the user asks for a prebuilt plan; it is refused when the last phase has no end, or while the user\'s screening rules out Fat loss. It cannot be undone, so confirm with the user first.')
            ->cli('plan', 'prebuilt-apply')
            ->input(PrebuiltPlanApplyInput::class)
            ->run(function (PrebuiltPlanApplyInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $prebuilt = PrebuiltPlan::from($input->plan);
                // CAP-019: another user's id is not found; the user's own is checked as a replay.
                $existing = $this->ownedOrNew(Phase::class, $input->first_phase_id, $user);
                $changed = ($this->apply)($user, $prebuilt, $input->first_phase_id, $existing);

                return new WriteResult(invalidates: PlanReads::keys($user, ...$changed), toast: ($prebuilt === PrebuiltPlan::MuscleGain ? 'Muscle gain' : 'Fat loss').' plan added');
            });
    }
}
