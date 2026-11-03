<?php

namespace App\Capabilities\Settings;

use App\Actions\Settings\UpdateProfile;
use App\Capabilities\HostCapability;
use App\Capabilities\Settings\Data\ProfileUpdateInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\WriteResult;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ProfileUpdateCapability implements HostCapability
{
    public function __construct(private readonly UpdateProfile $update) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('profile_update', affects: SettingsReads::REBUILDS)
            ->description('Save the user\'s profile, sending every field: name, sex, date of birth, height in cm or in feet and inches, body-fat bucket, whether they take a GLP-1 medicine, and training experience. When a change reaches the nutrition targets, the targets are recalculated from tomorrow; the profile is saved even if that fails, and settings_show then says so.')
            ->cli('profile', 'update')
            ->input(ProfileUpdateInput::class)
            ->run(function (ProfileUpdateInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                return new WriteResult(invalidates: SettingsReads::keys(($this->update)($user, $input)));
            });
    }
}
