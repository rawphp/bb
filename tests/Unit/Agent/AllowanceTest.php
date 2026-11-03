<?php

use App\Agent\Allowance;
use Carbon\CarbonImmutable;

$now = fn () => CarbonImmutable::parse('2026-11-02 15:30:00', 'UTC');

it('AGT-005 sets no cap when both limits are empty, the self-hosted default', function () use ($now) {
    $allowance = Allowance::check(null, null, turnsToday: 500, tokensThisMonth: 9_000_000, now: $now());

    expect($allowance->atCap)->toBeFalse()
        ->and($allowance->turnsLeft)->toBeNull()
        ->and($allowance->tokensLeft)->toBeNull()
        ->and($allowance->resetsAt)->toBeNull()
        ->and($allowance->notice)->toBeNull();
});

it('AGT-005 says nothing while the user is well under the caps', function () use ($now) {
    $allowance = Allowance::check(50, 1_000_000, turnsToday: 10, tokensThisMonth: 100_000, now: $now());

    expect($allowance->atCap)->toBeFalse()
        ->and($allowance->turnsLeft)->toBe(40)
        ->and($allowance->tokensLeft)->toBe(900_000)
        ->and($allowance->notice)->toBeNull();
});

it('AGT-005 says how many turns are left near the daily cap', function () use ($now) {
    $allowance = Allowance::check(50, null, turnsToday: 40, tokensThisMonth: 0, now: $now());

    expect($allowance->atCap)->toBeFalse()
        ->and($allowance->turnsLeft)->toBe(10)
        ->and($allowance->notice)->toBe('10 Coach messages left today.');

    expect(Allowance::check(50, null, turnsToday: 49, tokensThisMonth: 0, now: $now())->notice)
        ->toBe('1 Coach message left today.');
});

it('AGT-005 says how much of the monthly allowance is left near the token cap', function () use ($now) {
    $allowance = Allowance::check(null, 1_000_000, turnsToday: 0, tokensThisMonth: 850_000, now: $now());

    expect($allowance->atCap)->toBeFalse()
        ->and($allowance->tokensLeft)->toBe(150_000)
        ->and($allowance->notice)->toBe('15% of this month\'s Coach allowance left.');
});

it('AGT-005 says when the daily cap resets once it is reached', function () use ($now) {
    $allowance = Allowance::check(50, null, turnsToday: 50, tokensThisMonth: 0, now: $now());

    expect($allowance->atCap)->toBeTrue()
        ->and($allowance->turnsLeft)->toBe(0)
        ->and($allowance->resetsAt->toIso8601String())->toBe('2026-11-03T00:00:00+00:00')
        ->and($allowance->notice)->toBe('You\'ve reached today\'s Coach limit. It resets at 00:00 UTC on 3 Nov. Everything else still works.');
});

it('AGT-005 says when the monthly cap resets once it is reached, even past it', function () use ($now) {
    $allowance = Allowance::check(50, 1_000_000, turnsToday: 50, tokensThisMonth: 1_020_000, now: $now());

    expect($allowance->atCap)->toBeTrue()
        ->and($allowance->tokensLeft)->toBe(0)
        ->and($allowance->resetsAt->toIso8601String())->toBe('2026-12-01T00:00:00+00:00')
        ->and($allowance->notice)->toBe('You\'ve reached this month\'s Coach limit. It resets at 00:00 UTC on 1 Dec. Everything else still works.');
});

it('SET-012 says the daily cap resets at the end of the user\'s day, in their zone', function (string $dayEndsAt, string $zone, string $resetsAt, string $at) use ($now) {
    $allowance = Allowance::check(50, null, turnsToday: 50, tokensThisMonth: 0, now: $now(), dayEndsAt: CarbonImmutable::parse($dayEndsAt, $zone));

    expect($allowance->atCap)->toBeTrue()
        ->and($allowance->resetsAt->toIso8601String())->toBe($resetsAt)
        ->and($allowance->notice)->toBe("You've reached today's Coach limit. It resets at {$at}. Everything else still works.");
})->with([
    'Australia/Brisbane' => ['2026-11-04 00:00:00', 'Australia/Brisbane', '2026-11-04T00:00:00+10:00', '00:00 AEST on 4 Nov'],
    'UTC' => ['2026-11-03 00:00:00', 'UTC', '2026-11-03T00:00:00+00:00', '00:00 UTC on 3 Nov'],
]);

it('SET-012 AGT-005 says the later reset when both caps are reached', function (string $now, string $dayEndsAt, string $zone, string $resetsAt, string $notice) {
    $allowance = Allowance::check(50, 1_000_000, turnsToday: 50, tokensThisMonth: 1_000_000, now: CarbonImmutable::parse($now, 'UTC'), dayEndsAt: CarbonImmutable::parse($dayEndsAt, $zone));

    expect($allowance->atCap)->toBeTrue()
        ->and($allowance->resetsAt->toIso8601String())->toBe($resetsAt)
        ->and($allowance->notice)->toBe("You've reached {$notice}. Everything else still works.");
})->with([
    // 01:00 on 1 Dec in Brisbane: the app's month ends at 00:00 UTC, nine hours away; the user's day ends 14 hours after that.
    'Australia/Brisbane' => ['2026-11-30 15:00:00', '2026-12-02 00:00:00', 'Australia/Brisbane', '2026-12-02T00:00:00+10:00', 'today\'s Coach limit. It resets at 00:00 AEST on 2 Dec'],
    // The day and the month end at the same instant: the month's notice, as before.
    'UTC' => ['2026-11-30 20:00:00', '2026-12-01 00:00:00', 'UTC', '2026-12-01T00:00:00+00:00', 'this month\'s Coach limit. It resets at 00:00 UTC on 1 Dec'],
    // 12:00 on 30 Nov in Los Angeles: the app's month ends at 00:00 UTC, the user's day eight hours later.
    'America/Los_Angeles' => ['2026-11-30 20:00:00', '2026-12-01 00:00:00', 'America/Los_Angeles', '2026-12-01T00:00:00-08:00', 'today\'s Coach limit. It resets at 00:00 PST on 1 Dec'],
]);

it('SET-012 AGT-005 still refuses at the monthly cap when the daily cap is not reached, however late the user\'s day ends', function (?int $turnsPerDay, string $now, string $dayEndsAt, string $zone) {
    $allowance = Allowance::check($turnsPerDay, 1_000_000, turnsToday: 10, tokensThisMonth: 1_000_000, now: CarbonImmutable::parse($now, 'UTC'), dayEndsAt: CarbonImmutable::parse($dayEndsAt, $zone));

    expect($allowance->atCap)->toBeTrue()
        ->and($allowance->resetsAt->toIso8601String())->toBe('2026-12-01T00:00:00+00:00')
        ->and($allowance->notice)->toBe('You\'ve reached this month\'s Coach limit. It resets at 00:00 UTC on 1 Dec. Everything else still works.');
})->with([
    'Australia/Brisbane' => [50, '2026-11-30 15:00:00', '2026-12-02 00:00:00', 'Australia/Brisbane'],
    'UTC' => [50, '2026-11-30 20:00:00', '2026-12-01 00:00:00', 'UTC'],
    'America/Los_Angeles, no daily cap' => [null, '2026-11-30 20:00:00', '2026-12-01 00:00:00', 'America/Los_Angeles'],
]);
