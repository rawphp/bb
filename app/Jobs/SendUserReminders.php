<?php

namespace App\Jobs;

use App\Actions\Notifications\SendDueReminders;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One user's reminders for one pass of reminders:send (NOTIF-001 to NOTIF-005). A job per user, so one user's data
 * cannot stop or slow the others' reminders. The job judges what is due when it runs, so a job held up in the queue
 * never sends a reminder more than the catch-up window late; one run more than that window after its pass is dropped
 * without reading the plan, and logged, so a queue that stays behind can be seen. A retry sends nothing twice, as
 * each reminder is claimed when it is sent.
 */
final class SendUserReminders implements ShouldQueue
{
    use Queueable;

    /** A user who deleted their account while this waited has nothing to send to (ACC-005). */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly User $user,
        public readonly CarbonImmutable $now,
    ) {}

    public function handle(SendDueReminders $send): void
    {
        $now = CarbonImmutable::now();
        if ($this->now->addMinutes(SendDueReminders::WINDOW_MINUTES)->isBefore($now)) {
            Log::warning('Reminder pass dropped: the queue ran it too late.', [
                'user_id' => $this->user->id, 'queued_at' => $this->now->toIso8601String(), 'ran_at' => $now->toIso8601String(),
            ]);

            return;
        }

        $send($this->user, $now);
    }
}
