<?php

namespace App\Domain\Notifications;

use DateTimeImmutable;

/** One notification and when it is due. `$id` is unique per user, so it is sent once. */
final readonly class PlannedReminder
{
    /** @param  string  $url  the app path the notification opens (NOTIF-011) */
    public function __construct(
        public string $id,
        public ReminderKind $kind,
        public string $title,
        public string $body,
        public DateTimeImmutable $fireAt,
        public string $url,
    ) {}
}
