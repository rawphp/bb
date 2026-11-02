<?php

namespace App\Capabilities\Plan;

use App\Actions\Plan\ChangePhaseGoal;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PhaseGoalChangeInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Domain\Model\PhaseType;
use App\Models\Phase;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PhaseGoalChangeCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly ChangePhaseGoal $changeGoal) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('phase_goal_change', affects: PlanReads::AFFECTS)
            ->description('Change a phase\'s goal (Fat loss, Muscle gain, Maintenance or Mini-cut) from the next plan week, as one of phase_show\'s goals previews it. A running phase ends there and a new phase of the new type takes its remaining weeks; the next mesocycle takes the new training defaults. Use it when the user wants a different goal; the warnings phase_show lists never block it.')
            ->cli('phase', 'goal-change')
            ->input(PhaseGoalChangeInput::class)
            ->run(function (PhaseGoalChangeInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $type = PhaseType::from($input->type);
                $existing = $this->ownedOrNew(Phase::class, $input->new_phase_id, $user);
                // CAP-019: a replayed change finds its new phase already written.
                $changed = $existing !== null
                    ? [$input->phase_id, (string) $existing->id]
                    : ($this->changeGoal)($user, $input->phase_id, $type, $input->new_phase_id);

                return new WriteResult(invalidates: PlanReads::keys($user, ...$changed), toast: "Goal is now {$type->title()}");
            });
    }

    public function owned(User $user): array
    {
        return ['phase_id' => Phase::query()->where('user_id', $user->id)];
    }
}
