<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;

/**
 * A push the push service did not accept leaves a warning. An expired
 * subscription is not a failure to report: the channel has already deleted it.
 * Whether a lost reminder is sent again is the scheduler's decision (NOTIF).
 */
final class LogFailedPush
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->report->isSubscriptionExpired()) {
            return;
        }

        Log::warning('Web Push send failed', [
            'subscription' => $event->subscription->getKey(),
            'host' => parse_url($event->report->getEndpoint(), PHP_URL_HOST),
            'status' => $event->report->getResponse()?->getStatusCode(),
            'reason' => $event->report->getReason(),
        ]);
    }
}
