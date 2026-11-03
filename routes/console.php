<?php

use App\Actions\Notifications\SendDueReminders;
use App\Billing\PlanPrices;
use App\Jobs\SendUserReminders;
use App\Models\AuditOutboxRow;
use App\Models\AuditRecord;
use App\Models\ClearedCoachTurn;
use App\Models\ReminderMorning;
use App\Models\SentReminder;
use App\Models\UndoToken;
use App\Models\User;
use App\Notifications\PushNotification;
use App\Support\Notifications\ReminderDays;
use App\Support\UserDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Activity keeps 90 days (CAP-022), and so do the audit rows the package wrote before it.
// A cleared conversation's turns count toward the caps until their month is over (AGT-005).
Schedule::command('model:prune', ['--model' => [AuditRecord::class, AuditOutboxRow::class, ClearedCoachTurn::class]])->daily();

// The package does not schedule its reaper; the host does, once (AGT setup defaults).
Schedule::command('capabilities-ai:reap-stale-turns')->everyFiveMinutes()
    // A run killed mid-way frees its lock after 10 minutes, not Laravel's default day.
    ->withoutOverlapping(10);

// Expired undo tokens hold copies of the rows their write changed (CAP-013): hourly, so none outlives its 24 hours by more than one.
Schedule::command('model:prune', ['--model' => [UndoToken::class]])->hourly();

// The paywall's prices are read from Stripe here, not in billing_show (SUB-002). Billing on only.
Artisan::command('billing:refresh-prices', function (PlanPrices $prices): int {
    $failed = array_filter(
        array_filter((array) config('billing.prices')),
        fn (mixed $price): bool => $prices->refresh((string) $price) === null,
    );
    foreach (array_keys($failed) as $plan) {
        $this->error("Stripe did not give the {$plan} price; the last one is kept.");
    }

    return $failed === [] ? 0 : 1;
})->purpose('Read the price of every plan on sale from Stripe and keep it for the paywall');

Schedule::command('billing:refresh-prices')->everyFifteenMinutes()->when(fn (): bool => (bool) config('billing.enabled'));

// NOTIF-001 to NOTIF-005: the server decides when each reminder is due and sends it, one queued job per user. Only
// users with a subscribed browser have anywhere to send to; without VAPID keys the pass does not run.
Artisan::command('reminders:send', function (): int {
    $now = CarbonImmutable::now();
    // A cheap set-based check first, so a job is queued only for a user who may have something due. Reminders fall due
    // at the user's clock time (SET-012), so the check runs once per zone, on that zone's last few minutes. The job
    // gets the real instant: it judges lateness on real instants.
    User::query()->whereHas('pushSubscriptions')->select(['id', 'time_zone'])->chunkById(500, function (Collection $users) use ($now) {
        foreach ($users->groupBy('time_zone') as $zone => $inZone) {
            $local = UserDay::clockIn(new DateTimeZone((string) $zone), $now);
            $due = ReminderDays::mayHaveDue(array_map('intval', $inZone->modelKeys()), $local->modify('-'.SendDueReminders::WINDOW_MINUTES.' minutes'), $local);
            $inZone->whereIn('id', $due)->each(fn (User $user) => SendUserReminders::dispatch($user, $now));
        }
    });

    return 0;
})->purpose('Queue each subscribed user\'s reminders that fell due in the last few minutes');

Schedule::command('reminders:send')->everyMinute()->withoutOverlapping(10)->when(fn (): bool => PushNotification::available());

// A reminder is sent within minutes of falling due, and a judged morning matters only that morning, so both logs
// keep two days.
Schedule::command('model:prune', ['--model' => [SentReminder::class, ReminderMorning::class]])->daily();
