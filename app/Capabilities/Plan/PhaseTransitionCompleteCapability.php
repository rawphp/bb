<?php

namespace App\Capabilities\Plan;

use App\Actions\Plan\CompletePhaseTransition;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PhaseTransitionCompleteInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use App\Domain\Model\PhaseType;
use App\Models\Phase;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PhaseTransitionCompleteCapability implements HostCapability, OwnsInput
{
    use UsesClientUlid;

    public function __construct(private readonly CompletePhaseTransition $complete) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('phase_transition_complete', affects: PlanReads::AFFECTS)
            ->description('Choose what comes after a phase that is ending, as plan_show\'s transition offers it: the next phase type with one of its lengths and training templates, or Gain first after a cut (three phases). The new phases start where the ending phase ends. Use it once the user has picked from the transition screen; it is refused when no transition is due.')
            ->cli('phase', 'transition-complete')
            ->input(PhaseTransitionCompleteInput::class)
            ->run(function (PhaseTransitionCompleteInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $choice = PhaseType::from($input->choice);
                // CAP-019: another user's id is not found; the user's own is checked as a replay.
                $existing = $this->ownedOrNew(Phase::class, $input->next_phase_id, $user);
                $changed = ($this->complete)(
                    $user, $input->phase_id, $input->next_phase_id, $choice, $input->gain_first, $input->weeks,
                    $input->template, $input->calorie_mode, $input->step_kcal, $existing,
                );

                return new WriteResult(invalidates: PlanReads::keys($user, ...$changed), toast: ($input->gain_first ? 'Gain first' : $choice->title()).' comes next');
            });
    }

    public function owned(User $user): array
    {
        return ['phase_id' => Phase::query()->where('user_id', $user->id)];
    }
}
