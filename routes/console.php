<?php

use App\Models\AuditRecord;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Activity keeps 90 days (CAP-022).
Schedule::command('model:prune', ['--model' => [AuditRecord::class]])->daily();
