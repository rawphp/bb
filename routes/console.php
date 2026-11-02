<?php

use App\Models\AuditRecord;
use App\Models\UndoToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Activity keeps 90 days (CAP-022).
Schedule::command('model:prune', ['--model' => [AuditRecord::class]])->daily();

// The package does not schedule its reaper; the host does, once (AGT setup defaults).
Schedule::command('capabilities-ai:reap-stale-turns')->everyFiveMinutes()
    // A run killed mid-way frees its lock after 10 minutes, not Laravel's default day.
    ->withoutOverlapping(10);

// Expired undo tokens hold copies of the rows their write changed (CAP-013): hourly, so none outlives its 24 hours by more than one.
Schedule::command('model:prune', ['--model' => [UndoToken::class]])->hourly();
