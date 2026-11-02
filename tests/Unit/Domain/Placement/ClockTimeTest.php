<?php

use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;

it('SCH-045 a clock time wraps around midnight and reads a stored HH:mm', function () {
    expect((string) new ClockTime(1440))->toBe('00:00')
        ->and((string) new ClockTime(-30))->toBe('23:30')
        ->and(ClockTime::at(23)->minutesUntil(ClockTime::at(1)))->toBe(120)
        ->and(ClockTime::fromHhmm('07:05')?->minuteOfDay)->toBe(425)
        ->and(ClockTime::fromHhmm('24:00'))->toBeNull()
        ->and(ClockTime::fromHhmm('07:60'))->toBeNull()
        ->and(ClockTime::fromHhmm('late'))->toBeNull()
        ->and(ClockTime::fromHhmm('07:05'."\n"))->toBeNull()
        ->and(ClockTime::fromHhmm('07:5x'))->toBeNull();
});

it('SCH-045 an HH:mm reads as Swift reads it: unpadded, and with seconds ignored', function () {
    expect(ClockTime::fromHhmm('7:5')?->minuteOfDay)->toBe(425)
        ->and(ClockTime::fromHhmm('07:05:00')?->minuteOfDay)->toBe(425)
        ->and(ClockTime::fromHhmm('007:05')?->minuteOfDay)->toBe(425);
});

it('SCH-045 a range crossing midnight measures and orders times across it', function () {
    $night = new ClockRange(ClockTime::at(18), ClockTime::at(6));

    expect($night->lengthInMinutes())->toBe(720)
        ->and($night->offset(ClockTime::at(1)))->toBe(420)
        ->and($night->offset(ClockTime::at(17, 30)))->toBe(-30);
});

it('SCH-056 a time of day shows as "5:30 PM"', function () {
    expect(ClockTime::at(17, 30)->shortText())->toBe('5:30 PM')
        ->and(ClockTime::at(0, 5)->shortText())->toBe('12:05 AM')
        ->and(ClockTime::at(12)->shortText())->toBe('12:00 PM');
});
