<?php

namespace App\Capabilities\Push;

use App\Actions\Push\UnsubscribePush;
use App\Capabilities\HostCapability;
use App\Capabilities\Push\Data\PushUnsubscribeInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PushUnsubscribeCapability implements HostCapability
{
    public function __construct(private readonly UnsubscribePush $unsubscribe) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('push_unsubscribe', affects: ['push_show'])
            ->description('Stop sending Web Push notifications to one of the user\'s browsers, named by its endpoint. Other browsers keep theirs.')
            ->cli('push', 'unsubscribe')
            ->input(PushUnsubscribeInput::class)
            ->run(function (PushUnsubscribeInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                ($this->unsubscribe)($user, $input->endpoint);

                return new WriteResult(invalidates: [ReadKey::for('push_show')]);
            });
    }
}
