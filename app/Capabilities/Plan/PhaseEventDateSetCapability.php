<?php

namespace App\Capabilities\Plan;

use App\Actions\Plan\SetPhaseEventDate;
use App\Capabilities\HostCapability;
use App\Capabilities\Plan\Data\PhaseEventDateSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\WriteResult;
use App\Models\Phase;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PhaseEventDateSetCapability implements HostCapability, OwnsInput
{
    public function __construct(private readonly SetPhaseEventDate $setEventDate) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('phase_event_date_set', affects: PlanReads::AFFECTS)
            ->description('Set the date of an event the phase leads up to (a competition, wedding or holiday), or clear it with null. The Plan screen counts down to it, and phase_show then warns when the phase runs past it and offers to end the phase in the event\'s week. Use it when the user names an event for a phase or wants it cleared.')
            ->cli('phase', 'event-date-set')
            ->input(PhaseEventDateSetInput::class)
            ->run(function (PhaseEventDateSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();
                $changed = ($this->setEventDate)($user, $input->phase_id, $input->event_date);

                return new WriteResult(invalidates: PlanReads::keys($user, ...$changed), toast: $input->event_date === null ? 'Event date cleared' : 'Event date set');
            });
    }

    public function owned(User $user): array
    {
        return ['phase_id' => Phase::query()->where('user_id', $user->id)];
    }
}
