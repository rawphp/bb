<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A Web Push notification. Its `data.url` is the app path the service worker
 * opens when the notification is tapped (NOTIF-011).
 */
final class PushNotification extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
    ) {
        if (preg_match('~^/(?![/\\\\])~', $url) !== 1) {
            throw new InvalidArgumentException("A push notification opens an app path such as /today, not \"{$url}\".");
        }
    }

    /** Whether this instance has the VAPID keys it needs to send Web Push. */
    public static function available(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->data(['url' => $this->url]);
    }
}
