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
    expect(tickDays(TickedRange::rangeToShow(null, tickDay(10, 12), new DateTimeZone('UTC'))))
        ->toBe(['2026-10-10 00:00', '2026-10-16 00:00']);
});

it('SHOP-005 brings the ticked range back while it still runs through today', function (int $today, int $hour) {
    $saved = new TickedRange(tickRange(12, 14), ['food:broccoli']);

    expect(tickDays(TickedRange::rangeToShow($saved, tickDay($today, $hour), new DateTimeZone('UTC'))))
        ->toBe(['2026-10-12 00:00', '2026-10-14 00:00']);
})->with([
    'two days before it ends' => [12, 9],
    'the evening of its last day' => [14, 18],
]);

it('SHOP-005 shows the default range once the ticked range has ended, and none of its ticks', function () {
    $saved = new TickedRange(tickRange(10, 16), ['food:broccoli']);
    $shown = TickedRange::rangeToShow($saved, tickDay(20, 12), new DateTimeZone('UTC'));

    expect(tickDays($shown))->toBe(['2026-10-20 00:00', '2026-10-26 00:00'])
        ->and($saved->keysIn($shown))->toBe([]);
});

it('SHOP-005 keeps ticks to the range they were made in', function () {
    $saved = new TickedRange(tickRange(10, 16), ['food:broccoli', 'manual:01JBX5H0000000000000000001']);

    expect($saved->keysIn(tickRange(10, 16)))->toBe(['food:broccoli', 'manual:01JBX5H0000000000000000001'])
        ->and($saved->keysIn(tickRange(10, 12)))->toBe([])
        ->and($saved->keysIn(new DateRange(tickDay(10, 15), tickDay(16, 9))))->toBe(['food:broccoli', 'manual:01JBX5H0000000000000000001']);
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

it('SHOP-005 a tick in another range clears the ticks of the old one', function () {
    $saved = new TickedRange(tickRange(10, 16), ['food:broccoli', 'manual:01JBX5H0000000000000000001']);

    $ticked = TickedRange::check($saved, tickRange(10, 12), 'food:spinach', true);

    expect(tickDays($ticked->range))->toBe(['2026-10-10 00:00', '2026-10-12 00:00'])
        ->and($ticked->keys)->toBe(['food:spinach']);
});

it('OFF-030 an untick sent from another range, such as a stale one replayed from the outbox, leaves the ticks alone', function () {
    $saved = new TickedRange(tickRange(10, 16), ['food:broccoli']);

    $after = TickedRange::check($saved, tickRange(17, 23), 'food:spinach', false);

    expect(tickDays($after->range))->toBe(['2026-10-10 00:00', '2026-10-16 00:00'])
        ->and($after->keys)->toBe(['food:broccoli']);
});

it('SHOP-005 a tick in the range already shown keeps the other ticks', function () {
    $saved = new TickedRange(tickRange(10, 16), ['food:broccoli']);

    expect(TickedRange::check($saved, new DateRange(tickDay(10, 8), tickDay(16, 20)), 'food:spinach', true)->keys)
        ->toBe(['food:broccoli', 'food:spinach']);
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
    'empty' => ['', false],
]);
