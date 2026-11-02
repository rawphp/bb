<?php

use App\Domain\Model\Age;

it('NUT-001 SAFE-001 age is whole years and turns over on the birthday', function () {
    $birth = new DateTimeImmutable('1980-10-11 00:00 UTC');

    expect(Age::years($birth, new DateTimeImmutable('2026-10-10 23:59 UTC')))->toBe(45)
        ->and(Age::years($birth, new DateTimeImmutable('2026-10-11 00:00 UTC')))->toBe(46)
        ->and(Age::years($birth, new DateTimeImmutable('1980-10-11 00:00 UTC')))->toBe(0);
});

it('NUT-001 SAFE-001 each date is read as the calendar day it is in its own time zone', function () {
    // 11 October in Auckland is still 10 October in UTC; 10 October 22:00 in New York is 11 October in UTC.
    $birth = new DateTimeImmutable('1980-10-11 00:00', new DateTimeZone('Pacific/Auckland'));

    expect(Age::years($birth, new DateTimeImmutable('2026-10-11 00:00 UTC')))->toBe(46)
        ->and(Age::years($birth, new DateTimeImmutable('2026-10-10 22:00', new DateTimeZone('America/New_York'))))->toBe(45);
});

it('NUT-001 SAFE-001 a 29 February birthday turns over on 1 March in a common year', function () {
    $birth = new DateTimeImmutable('2000-02-29 00:00 UTC');

    expect(Age::years($birth, new DateTimeImmutable('2025-02-28 00:00 UTC')))->toBe(24)
        ->and(Age::years($birth, new DateTimeImmutable('2025-03-01 00:00 UTC')))->toBe(25)
        ->and(Age::years($birth, new DateTimeImmutable('2028-02-28 00:00 UTC')))->toBe(27)
        ->and(Age::years($birth, new DateTimeImmutable('2028-02-29 00:00 UTC')))->toBe(28);
});
