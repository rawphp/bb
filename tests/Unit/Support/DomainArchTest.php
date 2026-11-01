<?php

use Tests\Support\DomainArch;

it('the domain date check catches every way of building a date', function (string $code) {
    expect(preg_match(DomainArch::BUILDS_DATE, $code))->toBe(1);
})->with([
    'new DateTimeImmutable()',
    'new \DateTimeImmutable',
    'new DateTime("today")',
    'new \DatePeriod($a, $b, $c)',
    'DateTimeImmutable::createFromFormat("Y", "2026")',
    '\DateTime::createFromInterface($d)',
]);

it('the domain date check allows time zones and moving a date it was given', function (string $code) {
    expect(preg_match(DomainArch::BUILDS_DATE, $code))->toBe(0);
})->with([
    "new DateTimeZone('UTC')",
    'DateTimeImmutable $today',
    "\$today->modify('-1 day')",
    'DateTimeInterface::ATOM',
]);

it('the domain date check refuses a folder with no files', function () {
    DomainArch::filesThatBuildDates('NoSuchFolder');
})->throws(RuntimeException::class);
