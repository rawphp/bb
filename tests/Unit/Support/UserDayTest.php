<?php

use App\Models\User;
use App\Support\UserDay;
use Illuminate\Support\Carbon;

// SET-012: the one place a real instant becomes the user's day or clock time.

afterEach(fn () => Carbon::setTestNow());

function userDayUser(?string $zone): User
{
    return (new User)->forceFill($zone === null ? [] : ['time_zone' => $zone]);
}

/** @param  array{0: DateTimeImmutable, 1: DateTimeImmutable}  $bounds */
function userDayBounds(array $bounds): array
{
    return [$bounds[0]->format('Y-m-d H:i:s e'), $bounds[1]->format('Y-m-d H:i:s e')];
}

it('SET-012 reads the clock in the user\'s time zone', function (string $zone, string $now, string $today, string $dayOf, string $first, string $last) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = userDayUser($zone);

    expect(UserDay::zone($user)->getName())->toBe($zone)
        ->and(UserDay::now($user)->format('Y-m-d H:i:s e'))->toBe("{$now} UTC")
        ->and(UserDay::today($user)->format('Y-m-d H:i:s e'))->toBe("{$today} 00:00:00 UTC")
        ->and(UserDay::dayOf($user, '2026-10-09 21:58:00')->format('Y-m-d H:i:s e'))->toBe("{$dayOf} 00:00:00 UTC")
        ->and(UserDay::dayOf($user, new DateTimeImmutable('2026-10-09T21:58:00Z'))->format('Y-m-d'))->toBe($dayOf)
        ->and(userDayBounds(UserDay::bounds($user, UserDay::today($user))))->toBe(["{$first} UTC", "{$last} UTC"]);
})->with([
    'Brisbane' => ['Australia/Brisbane', '2026-10-10 07:58:00', '2026-10-10', '2026-10-10', '2026-10-09 14:00:00', '2026-10-10 13:59:59'],
    'UTC' => ['UTC', '2026-10-09 21:58:00', '2026-10-09', '2026-10-09', '2026-10-09 00:00:00', '2026-10-09 23:59:59'],
    'New York' => ['America/New_York', '2026-10-09 17:58:00', '2026-10-09', '2026-10-09', '2026-10-09 04:00:00', '2026-10-10 03:59:59'],
]);

it('SET-012 counts a day the clocks go back as 25 hours', function () {
    expect(userDayBounds(UserDay::bounds(userDayUser('America/New_York'), new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')))))
        ->toBe(['2026-11-01 04:00:00 UTC', '2026-11-02 04:59:59 UTC']);
});

it('SET-012 treats a user who never sent a time zone as UTC', function () {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = userDayUser(null);

    expect(UserDay::zone($user)->getName())->toBe('UTC')
        ->and(UserDay::today($user)->format('Y-m-d'))->toBe('2026-10-09');
});
