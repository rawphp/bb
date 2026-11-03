<?php

use App\Capabilities\Settings\SettingsReads;
use Illuminate\Support\Carbon;

// CAP-011: what a settings write tells the client to refetch. Today is Wednesday 14 October 2026; the plan week runs
// Monday 12 to Sunday 18 October, so a rebuild from tomorrow writes 15 to 18 October.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('SET-004 a save that rebuilt nothing refetches settings_show only', function () {
    expect(SettingsReads::keys([]))->toBe(['settings_show{}']);
});

it('SET-004 a rebuild refetches settings_show, each rebuilt day\'s Today, and the strip of every day of its week', function () {
    $keys = SettingsReads::keys(['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18']);

    expect($keys)->toContain('settings_show{}', 'today_show{"date":"2026-10-15"}', 'today_show{"date":"2026-10-18"}', 'week_show{}')
        ->and(array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'week_show{"'))))->toBe(array_map(
            fn (string $date) => 'week_show{"date":"'.$date.'"}',
            ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'],
        ));
});
