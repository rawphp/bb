<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The package does not schedule its reaper; the host does, once (AGT setup defaults).
Schedule::command('capabilities-ai:reap-stale-turns')->everyFiveMinutes()
    // A run killed mid-way frees its lock after 10 minutes, not Laravel's default day.
    ->withoutOverlapping(10);
