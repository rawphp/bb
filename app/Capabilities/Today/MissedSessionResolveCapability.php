<?php

namespace App\Capabilities\Today;

use App\Actions\Today\ResolveMissedSession;
use App\Capabilities\HostCapability;
use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Today\Data\MissedSessionResolveInput;
use App\Models\Mesocycle;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class MissedSessionResolveCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly ResolveMissedSession $resolve) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        // UI-010, SCH-056: a move re-targets the day's meals and shows a toast with Undo.
        return HostDefinition::write('missed_session_resolve', affects: ['today_show', 'week_show', 'train_show', 'mesocycle_show', 'workout_show', ...TodayReads::SHEETS], reversible: true)
            ->description('Answer the missed-day prompt for a session of this plan week whose day passed with nothing logged: do it today (only on a rest day), do it on the next rest day of the plan week, or skip it. A move re-targets that day\'s unchecked meals around the session; a skip changes no targets. Only the choices the prompt offers are accepted.')
            ->cli('workout', 'missed-resolve')
            ->input(MissedSessionResolveInput::class)
            ->run(function (MissedSessionResolveInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $resolved = ($this->resolve)($user, $input);

                return new WriteResult(
                    invalidates: [
                        ...MesocycleReads::keys($input->mesocycle_id),
                        TodayReads::workout($input->mesocycle_id, $input->week, $input->day),
                        ...TodayReads::keys($resolved['dates'], TodayReads::SHEETS),
                        // SCH-022: the strip marks the days that hold a session.
                        ...TodayReads::weekKeys($resolved['week']),
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
