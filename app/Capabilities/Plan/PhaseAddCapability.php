<?php

namespace App\Capabilities\Plan;

use App\Actions\Plan\AddPhase;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PhaseAddInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Domain\Model\PhaseType;
use App\Models\Phase;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PhaseAddCapability implements HostCapability
{
    use UsesClientUlid;

    public function __construct(private readonly AddPhase $addPhase) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('phase_add', affects: PlanReads::AFFECTS)
            ->description('Add a phase (Fat loss, Muscle gain, Maintenance or Mini-cut) to the end of the plan, starting where the last phase ends, with one of the lengths and rates plan_show\'s add_phase lists. Leave the rate out for the type\'s default; only Maintenance may have no end. Use it when the user wants to plan what comes after their last phase; it is refused when the last phase has no end, and a cut is refused while the user\'s screening rules out Fat loss.')
            ->cli('phase', 'add')
            ->input(PhaseAddInput::class)
            ->run(function (PhaseAddInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $type = PhaseType::from($input->type);
                // CAP-019: another user's id is not found; the user's own is checked as a replay.
                $existing = $this->ownedOrNew(Phase::class, $input->phase_id, $user);
                $changed = ($this->addPhase)($user, $input->phase_id, $type, $input->weeks, $input->rate_percent_per_week, $existing);

                return new WriteResult(invalidates: PlanReads::keys($user, ...$changed), toast: "{$type->title()} added to your plan");
            });
    }
}
