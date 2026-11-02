<?php

namespace App\Capabilities\Settings;

use App\Actions\Notifications\SetNotificationPref;
use App\Capabilities\HostCapability;
use App\Capabilities\Settings\Data\NotificationPrefsSetInput;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use App\Domain\Notifications\ReminderKind;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class NotificationPrefsSetCapability implements HostCapability
{
    public function __construct(private readonly SetNotificationPref $set) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('notification_prefs_set', affects: ['settings_show'])
            ->description('Turn one type of notification on or off: weigh-in, meal, workout, weekly review, next mesocycle or meal target change reminders. Meal reminders start off and the rest start on. It changes only which notifications the server sends this user.')
            ->cli('notifications', 'set')
            ->input(NotificationPrefsSetInput::class)
            ->run(function (NotificationPrefsSetInput $input, CapabilityContext $context): WriteResult {
                /** @var User $user */
                $user = $context->user();

                ($this->set)($user, ReminderKind::from($input->kind), $input->on);

                return new WriteResult(invalidates: [ReadKey::for('settings_show')]);
            });
    }
}
