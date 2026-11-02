<?php

use App\Billing\PlanPrices;
use App\Models\AuditOutboxRow;
use App\Models\AuditRecord;
use App\Models\UndoToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Activity keeps 90 days (CAP-022), and so do the audit rows the package wrote before it.
Schedule::command('model:prune', ['--model' => [AuditRecord::class, AuditOutboxRow::class]])->daily();

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
