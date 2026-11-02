<?php

use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\TickedRange;

// Which range the list shows and which lines are ticked in it (rp-clone ShoppingListModel and ShoppingListStore).

function tickDay(int $day, int $hour = 0): DateTimeImmutable
{
    return new DateTimeImmutable(sprintf('2026-10-%02d %02d:00', $day, $hour), new DateTimeZone('UTC'));
}

function tickRange(int $first, int $last): DateRange
{
    return new DateRange(tickDay($first), tickDay($last));
}

/** @return array{string, string} */
function tickDays(DateRange $range): array
{
    return [$range->lower->format('Y-m-d H:i'), $range->upper->format('Y-m-d H:i')];
}

it('SHOP-001 shows the next 7 days from today when no ticks are kept', function () {
    expect(tickDays(TickedRange::rangeToShow([], tickDay(10, 12), new DateTimeZone('UTC'))))
        ->toBe(['2026-10-10 00:00', '2026-10-16 00:00']);
});

it('SHOP-005 brings back the ticked range that starts latest while it still runs through today', function (int $today, int $hour) {
    $saved = [new TickedRange(tickRange(10, 16), ['food:spinach']), new TickedRange(tickRange(12, 14), ['food:broccoli'])];

    expect(tickDays(TickedRange::rangeToShow($saved, tickDay($today, $hour), new DateTimeZone('UTC'))))
        ->toBe(['2026-10-12 00:00', '2026-10-14 00:00']);
})->with([
    'two days before it ends' => [12, 9],
    'the evening of its last day' => [14, 18],
]);

it('SHOP-005 passes over a ticked range that has ended for the last one still running, else the next 7 days', function () {
    $saved = [new TickedRange(tickRange(10, 16), ['food:broccoli']), new TickedRange(tickRange(17, 23), ['food:spinach'])];

    expect(tickDays(TickedRange::rangeToShow($saved, tickDay(17, 12), new DateTimeZone('UTC'))))->toBe(['2026-10-17 00:00', '2026-10-23 00:00'])
        ->and(tickDays(TickedRange::rangeToShow($saved, tickDay(24, 12), new DateTimeZone('UTC'))))->toBe(['2026-10-24 00:00', '2026-10-30 00:00']);
});

it('SHOP-001 SHOP-005 a ticked range that has not started, or has no ticks left, does not take over the list', function () {
    $saved = [
        new TickedRange(tickRange(4, 10), ['food:broccoli']),
        new TickedRange(tickRange(11, 17), ['food:spinach']),
        new TickedRange(tickRange(5, 11), []),
    ];

    expect(tickDays(TickedRange::rangeToShow($saved, tickDay(5, 12), new DateTimeZone('UTC'))))->toBe(['2026-10-04 00:00', '2026-10-10 00:00'])
        ->and(tickDays(TickedRange::rangeToShow($saved, tickDay(11, 12), new DateTimeZone('UTC'))))->toBe(['2026-10-11 00:00', '2026-10-17 00:00']);
});

it('SHOP-005 knows when a ticked range has ended: the day after its last', function () {
    $ticked = new TickedRange(tickRange(10, 16), ['food:broccoli']);
    $utc = new DateTimeZone('UTC');

    expect($ticked->hasEnded(tickDay(16, 23), $utc))->toBeFalse()
        ->and($ticked->hasEnded(tickDay(17, 0), $utc))->toBeTrue();
});

it('SHOP-005 shows each range its own ticks, and none for a range with no ticks', function () {
    $saved = [new TickedRange(tickRange(10, 16), ['food:broccoli']), new TickedRange(tickRange(17, 23), ['food:spinach'])];

    expect(TickedRange::keysIn($saved, tickRange(10, 16)))->toBe(['food:broccoli'])
        ->and(TickedRange::keysIn($saved, new DateRange(tickDay(17, 15), tickDay(23, 9))))->toBe(['food:spinach'])
        ->and(TickedRange::keysIn($saved, tickRange(10, 12)))->toBe([]);
});

it('SHOP-005 ticks a line off and takes the tick back', function () {
    $ticked = TickedRange::check(null, tickRange(10, 16), 'food:broccoli', true);
    $ticked = TickedRange::check($ticked, tickRange(10, 16), 'food:spinach', true);

    expect($ticked->keys)->toBe(['food:broccoli', 'food:spinach'])
        ->and(TickedRange::check($ticked, tickRange(10, 16), 'food:broccoli', false)->keys)->toBe(['food:spinach']);
});

it('OFF-030 a tick names the state it leaves, so a tick replayed or sent twice lands once', function () {
    $once = TickedRange::check(null, tickRange(10, 16), 'food:broccoli', true);
    $twice = TickedRange::check($once, tickRange(10, 16), 'food:broccoli', true);
    $untickedTwice = TickedRange::check(TickedRange::check($twice, tickRange(10, 16), 'food:broccoli', false), tickRange(10, 16), 'food:broccoli', false);

    expect($twice->keys)->toBe(['food:broccoli'])
        ->and($untickedTwice->keys)->toBe([]);
});

it('SHOP-005 an untick in a range with no ticks keeps nothing, so it moves no list', function () {
    expect(TickedRange::check(null, tickRange(10, 16), 'food:broccoli', false))->toBeNull();
});

it('SHOP-005 a deleted manual item takes its tick with it', function () {
    $saved = new TickedRange(tickRange(10, 16), ['food:broccoli', 'manual:01JBX5H0000000000000000001']);

    expect($saved->without('manual:01JBX5H0000000000000000001')->keys)->toBe(['food:broccoli'])
        ->and(tickDays($saved->without('manual:01JBX5H0000000000000000001')->range))->toBe(['2026-10-10 00:00', '2026-10-16 00:00']);
});

it('SHOP-005 reads the manual item a key names', function () {
    expect(TickedRange::manualId('manual:01JBX5H0000000000000000001'))->toBe('01JBX5H0000000000000000001')
        ->and(TickedRange::manualId('food:01JBX5H0000000000000000001'))->toBeNull();
});

it('SHOP-005 knows a line key: a food or a manual item, by its id', function (string $key, bool $valid) {
    expect(TickedRange::isKey($key))->toBe($valid);
})->with([
    'a food' => ['food:01jbx5f0000000000000000002', true],
    'a manual item' => ['manual:01JBX5H0000000000000000001', true],
    'no prefix' => ['01JBX5H0000000000000000001', false],
    'another prefix' => ['recipe:01JBX5H0000000000000000001', false],
    'not a ULID' => ['food:chicken', false],
    'one character short' => ['food:01JBX5F000000000000000000', false],
    'one character long' => ['manual:01JBX5H00000000000000000001', false],
    'empty' => ['', false],
]);
