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
 * reminders:send queues it every minute, one job per user; the window lets a late pass catch up without sending a
 * stale reminder.
 */
final class SendDueReminders
{
    /** How late a reminder may still go out. */
    public const WINDOW_MINUTES = 10;

    /** @return list<string> the ids of the reminders sent */
    public function __invoke(User $user, CarbonImmutable $now): array
    {
        $after = $now->subMinutes(self::WINDOW_MINUTES);
        // A workout's reminder is due the hour before it, so tomorrow's first hour can be due tonight.
        $days = ReminderDays::between($user, $after->toDateTimeImmutable(), $now->addMinutes(ReminderPlanner::WORKOUT_LEAD_MINUTES)->toDateTimeImmutable());
        $due = ReminderPlanner::due(ReminderPlanner::plan($days, NotificationPref::switches($user)), $after->toDateTimeImmutable(), $now->toDateTimeImmutable());

        $sent = [];
        foreach ($due as $reminder) {
            // The unique key claims the reminder, so two passes at once still send it once. The due time is part of
            // the key, so a meal or session moved later is reminded again at its new time. The claim commits with the
            // queued push: a push that cannot be queued leaves it unclaimed for the next pass.
            $claim = $reminder->id.'@'.$reminder->fireAt->format('H:i');
            DB::transaction(function () use ($user, $reminder, $claim, $now, &$sent) {
                if (SentReminder::query()->insertOrIgnore(['user_id' => $user->id, 'reminder_id' => $claim, 'sent_at' => $now]) === 1) {
                    SendPushNotification::dispatch($user, $reminder->title, $reminder->body, $reminder->url);
                    $sent[] = $reminder->id;
                }
            });
        }

        return $sent;
    }
}
