<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\PushShowInput;
use App\Capabilities\Reads\Data\PushShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use App\Notifications\PushNotification;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class PushShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('push_show')
            ->description('Show whether this instance can send Web Push notifications, the public key a browser subscribes with, how many of the user\'s browsers are subscribed, and whether the browser named by endpoint is one of them. It changes nothing.')
            ->cli('push', 'show')
            ->input(PushShowInput::class)
            ->output(PushShowOutput::class)
            ->run(fn (PushShowInput $input, CapabilityContext $context): PushShowOutput => $this->run($input, $context));
    }

    private function run(PushShowInput $input, CapabilityContext $context): PushShowOutput
    {
        /** @var User $user */
        $user = $context->user();
        $available = PushNotification::available();

        return new PushShowOutput(
            available: $available,
            public_key: $available ? config()->string('webpush.vapid.public_key') : null,
            devices: $user->pushSubscriptions()->count(),
            this_browser: $input->endpoint !== null && $user->pushSubscriptions()->where('endpoint', $input->endpoint)->exists(),
        );
    }
}
