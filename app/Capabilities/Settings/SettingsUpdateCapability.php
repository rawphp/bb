<?php

namespace App\Capabilities\Settings;

use App\Actions\Settings\UpdateSettings;
use App\Capabilities\HostCapability;
use App\Capabilities\Settings\Data\SettingsUpdateInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class SettingsUpdateCapability implements HostCapability
{
    public function __construct(private readonly UpdateSettings $update) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('settings_update', affects: SettingsReads::REBUILDS)
            ->description('Save the display settings, sending every field: the weight unit (kg or lb) for body weight and loads, the height unit (cm, or feet and inches), light, dark or system appearance, whether consumed totals include planned meals or only checked-in ones, and micro plates. Data is stored in metric, so a unit change converts every screen and rewrites no history. It does not change the targets, but it retries an update of them that failed after an earlier save.')
            ->cli('settings', 'update')
            ->input(SettingsUpdateInput::class)
            ->run(function (SettingsUpdateInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: SettingsReads::keys(($this->update)($user, $input)));
            });
    }
}
