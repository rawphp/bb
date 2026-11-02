<?php

namespace App\Jobs;

use App\Actions\Notifications\SendDueReminders;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One user's reminders for one pass of reminders:send (NOTIF-001 to NOTIF-005). A job per user, so one user's data
 * cannot stop or slow the others' reminders. A job run more than the catch-up window after its pass sends nothing:
 * after a queue backlog the newest pass covers the last few minutes, and older reminders are dropped, not sent in a
 * burst. A retry sends nothing twice, as each reminder is claimed when it is sent.
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
        if ($this->now->addMinutes(SendDueReminders::WINDOW_MINUTES)->isBefore(CarbonImmutable::now())) {
            return;
        }

        $send($this->user, $this->now);
    }
}
