<?php

use App\Domain\Placement\ClockTime;
use App\Domain\Placement\MealOrder;

$time = fn (array $slot): ?string => $slot['time'];
$names = fn (array $slots): array => array_map(fn (array $slot) => $slot['name'], $slots);

it('SCH-034 a meal with no time goes last', function () {
    expect(MealOrder::minuteOfHhmm('07:30'))->toBe(450)
        ->and(MealOrder::minuteOfHhmm(null))->toBe(ClockTime::MINUTES_PER_DAY)
        ->and(MealOrder::minuteOfHhmm('late'))->toBe(ClockTime::MINUTES_PER_DAY)
        ->and(MealOrder::minute(ClockTime::at(19, 30)))->toBe(1170)
        ->and(MealOrder::minute(null))->toBe(ClockTime::MINUTES_PER_DAY);
});

it('SCH-034 meals sort by time and keep their order at one minute', function () use ($time, $names) {
    $slots = [
        ['name' => 'none', 'time' => null],
        ['name' => 'dinner', 'time' => '19:00'],
        ['name' => 'a', 'time' => '08:00'],
        ['name' => 'b', 'time' => '08:00'],
    ];

    expect($names(MealOrder::inTimeOrder($slots, $time)))->toBe(['a', 'b', 'dinner', 'none']);
});

it('SCH-034 pairs go by position in time order', function () use ($time) {
    $source = [
        ['name' => 's-dinner', 'time' => '19:00'],
        ['name' => 's-breakfast', 'time' => '08:00'],
        ['name' => 's-lunch', 'time' => '12:30'],
    ];
    $destination = [['name' => 'd-late', 'time' => '20:00'], ['name' => 'd-early', 'time' => '07:00']];

    $pairs = MealOrder::pairs($source, $destination, $time);

    // Extra source meals are dropped: the dinner has no partner.
    expect(array_map(fn (array $pair) => "{$pair['source']['name']}>{$pair['destination']['name']}", $pairs))
        ->toBe(['s-breakfast>d-early', 's-lunch>d-late'])
        ->and(MealOrder::pairs($source, [], $time))->toBe([]);
});
