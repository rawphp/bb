<?php

namespace App\Capabilities\Reads;

use App\Actions\Settings\ShowHealthBridge;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\HealthBridgeShowInput;
use App\Capabilities\Reads\Data\HealthBridgeShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class HealthBridgeShowCapability implements HostCapability
{
    public function __construct(private readonly ShowHealthBridge $show) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('health_bridge_show')
            ->description('Show the Health bridge, the iOS Shortcut that posts the user\'s body weight and steps: whether it is set up, its token without the secret, when it last posted and what it carried, the shared Shortcut\'s link and the steps to build it by hand. token_create with the health_bridge scope sets it up and token_revoke stops it. It changes nothing.')
            ->cli('health-bridge', 'show')
            ->input(HealthBridgeShowInput::class)
            ->output(HealthBridgeShowOutput::class)
            ->run(fn (HealthBridgeShowInput $input, CapabilityContext $context): HealthBridgeShowOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): HealthBridgeShowOutput
    {
        /** @var User $user */
        $user = $context->user();

        return ($this->show)($user, CarbonImmutable::now());
    }
}
