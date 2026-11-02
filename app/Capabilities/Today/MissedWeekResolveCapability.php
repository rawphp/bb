<?php

namespace App\Capabilities\Today;

use App\Actions\Today\ResolveMissedWeek;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Plan\PlanReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\MissedWeekResolveInput;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MissedWeekResolveCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly ResolveMissedWeek $resolve) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // AGT-010: ending drops the weeks still to come, so the coach needs the user's say-so.
        return HostDefinition::write('missed_week_resolve', affects: ['today_show', 'week_show', 'plan_show', 'phase_show', 'train_show', 'mesocycle_show'], destructive: true)
            ->description('Answer the missed-weeks prompt, shown when a whole plan week or more passed with no training: restart the week the user stopped in, or end the mesocycle. A restart moves the mesocycle\'s start and its stored deload weeks forward by whole plan weeks and leaves the day targets alone; an end drops the weeks still to come and cannot be undone. Only the choices the prompt offers are accepted.')
            ->cli('mesocycle', 'missed-resolve')
            ->input(MissedWeekResolveInput::class)
            ->run(function (MissedWeekResolveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $resolved = ($this->resolve)($user, $input);

                return new WriteResult(
                    invalidates: [
                        ...MesocycleReads::keys($input->mesocycle_id),
                        ...TodayReads::keys($resolved['dates']),
                        ...TodayReads::weekKeys($resolved['dates']),
                        // The mesocycle's band and deloads move, and a diet break changes every phase's target weight.
                        ...PlanReads::everyPhaseKeys($user),
                    ],
                    toast: $resolved['toast'],
                );
            });
    }

    public function owned(User $user): array
    {
        return ['mesocycle_id' => Mesocycle::savedBy($user)];
    }
}
