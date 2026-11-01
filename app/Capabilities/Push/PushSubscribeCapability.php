<?php

namespace App\Capabilities\Push;

use App\Actions\Push\SubscribePush;
use App\Capabilities\HostCapability;
use App\Capabilities\Push\Data\PushSubscribeInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PushSubscribeCapability implements HostCapability
{
    public function __construct(private readonly SubscribePush $subscribe) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('push_subscribe', affects: ['push_show'])
            ->description('Save a browser\'s Web Push subscription so the server can send the user notifications there. The app calls it after the user allows notifications; subscribing the same browser again updates it.')
            ->cli('push', 'subscribe')
            ->input(PushSubscribeInput::class)
            ->run(function (PushSubscribeInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                ($this->subscribe)($user, $input->endpoint, $input->public_key, $input->auth_token);

                return new WriteResult(invalidates: [ReadKey::for('push_show')]);
            });
    }
}
