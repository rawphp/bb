<?php

namespace App\Capabilities\Settings;

use App\Actions\Settings\ShowSettings;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\SettingsShowInput;
use App\Capabilities\Reads\Data\SettingsShowOutput;
use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class SettingsShowCapability implements HostCapability
{
    public function __construct(private readonly ShowSettings $show) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('settings_show')
            ->description('Show Settings: the profile, units, appearance, consumed totals, diet filters, micro plates and schedule defaults with the choices each one allows, the notification switches, and the eligibility, Health bridge, tokens, Coach and subscription rows. A notice shows while the last save could not update the targets. It changes nothing.')
            ->cli('settings', 'show')
            ->input(SettingsShowInput::class)
            ->output(SettingsShowOutput::class)
            ->run(fn (SettingsShowInput $input, CapabilityContext $context): SettingsShowOutput => $this->run($context));
    }

    private function run(CapabilityContext $context): SettingsShowOutput
    {
        /** @var User $user */
        $user = $context->user();

        return ($this->show)($user, CarbonImmutable::now());
    }
}
