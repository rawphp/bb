<?php

namespace App\Capabilities\Coach;

use App\Actions\Coach\SetCoachEnabled;
use App\Capabilities\Coach\Data\CoachEnabledSetInput;
use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/** The user's own Coach switch (AGT-020). The agent cannot call it (CAP-031). */
final class CoachEnabledSetCapability implements HostCapability
{
    public function __construct(private readonly SetCoachEnabled $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('coach_enabled_set', affects: ['agent_status_show'])
            ->description('Turn the signed-in user\'s own Coach on or off. While it is off the Coach takes no new message from them; their conversation is kept. It changes only this switch.')
            ->cli('coach', 'enabled-set')
            ->input(CoachEnabledSetInput::class)
            ->run(function (CoachEnabledSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                ($this->set)($user, $input->on);

                return new WriteResult(invalidates: [ReadKey::for('agent_status_show')], toast: $input->on ? 'Coach on.' : 'Coach off.');
            });
    }
}
