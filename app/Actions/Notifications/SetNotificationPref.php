<?php

namespace App\Actions\Notifications;

use App\Domain\Notifications\ReminderKind;
use App\Models\NotificationPref;
use App\Models\User;

/** NOTIF-007: switches one reminder type on or off for the user. */
final class SetNotificationPref
{
    public function __invoke(User $user, ReminderKind $kind, bool $on): void
    {
        NotificationPref::query()->upsert(
            [['user_id' => $user->id, 'kind' => $kind->value, 'on' => $on, 'created_at' => now(), 'updated_at' => now()]],
            ['user_id', 'kind'],
            ['on', 'updated_at'],
        );
    }
}
