<?php

namespace App\Actions\Notifications;

use App\Domain\Notifications\ReminderPlanner;
use App\Jobs\SendPushNotification;
use App\Models\NotificationPref;
use App\Models\SentReminder;
use App\Models\User;
use App\Support\Notifications\ReminderDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-001 to NOTIF-005, NOTIF-007: sends the user's reminders that fell due in the last few minutes, each once.
 * reminders:send queues it every minute, one job per user; the window lets a late pass catch up without queueing a
 * push for a stale reminder.
 */
final class SendDueReminders
{
    /** How late a reminder's push may still be queued. */
    public const WINDOW_MINUTES = 10;

    /** @return list<string> the ids of the reminders sent */
    public function __invoke(User $user, CarbonImmutable $now): array
    {
        $after = $now->subMinutes(self::WINDOW_MINUTES);
        // Most minutes nothing can be due: a few small reads say so before the full read of the plan.
        if (! ReminderDays::mayHaveDue($user, $after->toDateTimeImmutable(), $now->toDateTimeImmutable())) {
            return [];
        }
        // A workout's reminder is due the hour before it, so tomorrow's first hour can be due tonight.
        $days = ReminderDays::between($user, $after->toDateTimeImmutable(), $now->addMinutes(ReminderPlanner::WORKOUT_LEAD_MINUTES)->toDateTimeImmutable());
        $due = ReminderPlanner::due(ReminderPlanner::plan($days, NotificationPref::switches($user)), $after->toDateTimeImmutable(), $now->toDateTimeImmutable());

        $sent = [];
        foreach ($due as $reminder) {
            DB::transaction(function () use ($user, $reminder, $now, &$sent) {
                // Sent since it fell due: the same reminder again, or one moved earlier after it went out. One moved
                // to a time after it was sent is due again at its new time.
                $already = SentReminder::query()->where('user_id', $user->id)->where('reminder_id', $reminder->id)
                    ->where('sent_at', '>=', $reminder->fireAt)->exists();
                // The unique key claims it, so two passes at once still send it once. The push is queued inside the
                // claim's transaction: a push that cannot be queued rolls the claim back for the next pass. On the
                // database queue the two commit together; on Redis a commit that fails after the push was queued
                // leaves it unclaimed, so it can go out twice.
                if (! $already && SentReminder::query()->insertOrIgnore([
                    'user_id' => $user->id, 'reminder_id' => $reminder->id, 'due_at' => $reminder->fireAt, 'sent_at' => $now,
                ]) === 1) {
                    SendPushNotification::dispatch($user, $reminder->title, $reminder->body, $reminder->url);
                    $sent[] = $reminder->id;
                }
            });
        }

        return $sent;
    }
}
