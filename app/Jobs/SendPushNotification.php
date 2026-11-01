<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\PushNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Send one Web Push notification to every browser the user subscribed. The
 * server decides when a notification is due and dispatches this; without
 * VAPID keys it sends nothing. Expired subscriptions are deleted by the channel.
 */
final class SendPushNotification implements ShouldQueue
{
    use Queueable;

    private readonly PushNotification $notification;

    public function __construct(
        public readonly User $user,
        string $title,
        string $body,
        string $url,
    ) {
        $this->notification = new PushNotification($title, $body, $url);
    }

    public function handle(): void
    {
        if (! PushNotification::available()) {
            return;
        }

        $this->user->notify($this->notification);
    }
}
