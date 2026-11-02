<?php

use App\Domain\WeighIns\WeighInSchedule;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/WeighIns/WeighInScheduleModelTests.swift and
// WeighInsModelTests.swift (missed days). Saturday 10 October 2026 is "today" throughout.

function scheduleDay(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

/** @param list<DateTimeImmutable> $days */
function scheduleDates(array $days): array
{
    return array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d H:i'), $days);
}

it('WT-001 names the days of an entry, Monday first, or every day', function () {
    expect(WeighInSchedule::daysLabel([5, 1, 3]))->toBe('Mon, Wed, Fri')
        ->and(WeighInSchedule::daysLabel([7, 1, 2, 3, 4, 5, 6]))->toBe('Every day')
        ->and(WeighInSchedule::daysLabel([6]))->toBe('Sat')
        ->and(array_keys(WeighInSchedule::WEEKDAYS))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(WeighInSchedule::WEEKDAYS[1])->toBe(['Mon', 'Monday'])
        ->and(WeighInSchedule::WEEKDAYS[7])->toBe(['Sun', 'Sunday']);
});

it('WT-001 says the time only sets the reminder', function () {
    expect(WeighInSchedule::TIME_NOTE)->toContain('only sets your reminder')
        ->and(WeighInSchedule::MINIMUM_DAYS)->toBe(2);
});

it('WT-001 counts the different weigh-in days across every entry', function () {
    expect(WeighInSchedule::summary([[1, 3, 5], [6]]))->toBe('Schedule: 4 days a week')
        ->and(WeighInSchedule::summary([[1, 3], [3]]))->toBe('Schedule: 2 days a week')
        ->and(WeighInSchedule::summary([[4]]))->toBe('Schedule: 1 day a week')
        ->and(WeighInSchedule::summary([]))->toBe('No weigh-in schedule yet');
});

it('WT-007 allows deleting an entry that leaves exactly 2 weigh-in days', function () {
    expect(WeighInSchedule::canDelete([[1, 2], [2, 3]], 1))->toBeTrue();
});

it('WT-007 blocks deleting an entry that leaves one weigh-in day', function () {
    expect(WeighInSchedule::canDelete([[1, 2, 3], [3]], 0))->toBeFalse()
        ->and(WeighInSchedule::canDelete([[1, 2, 3], [3]], 1))->toBeTrue();
});

it('WT-007 blocks deleting an entry when the entries left share one day', function () {
    expect(WeighInSchedule::canDelete([[1, 2], [3], [3]], 0))->toBeFalse();
});

it('WT-007 never deletes the only entry', function () {
    expect(WeighInSchedule::canDelete([[1, 2]], 0))->toBeFalse()
        ->and(WeighInSchedule::DELETE_BLOCKED)->toBe('Deleting this would leave fewer than 2 weigh-in days a week.');
});

it('WT-004 marks scheduled days with no weigh-in missed, newest first, from the first weigh-in up to yesterday', function () {
    // Schedule Mon, Wed, Fri, Sat. First weigh-in Mon 5 Oct; Tue 6 Oct is off schedule.
    $missed = WeighInSchedule::missedDays([1, 3, 5, 6], [scheduleDay('2026-10-06 08:00'), scheduleDay('2026-10-05 07:00')], scheduleDay('2026-10-10 12:00'));

    expect(scheduleDates($missed))->toBe(['2026-10-09 00:00', '2026-10-07 00:00']);
});

it('WT-004 marks yesterday missed but not today', function () {
    $missed = WeighInSchedule::missedDays([5, 6], [scheduleDay('2026-10-08 07:00')], scheduleDay('2026-10-10 12:00'));

    expect(scheduleDates($missed))->toBe(['2026-10-09 00:00']);
});

it('WT-004 marks nothing before the first weigh-in missed', function () {
    // Scheduled Mon 5 and Fri 2 Oct come before the first weigh-in on Tue 6 Oct.
    $missed = WeighInSchedule::missedDays([1, 5], [scheduleDay('2026-10-06 07:00')], scheduleDay('2026-10-10 12:00'));

    expect(scheduleDates($missed))->toBe(['2026-10-09 00:00']);
});

it('WT-004 counts a weigh-in late in the day for that day', function () {
    $missed = WeighInSchedule::missedDays([3, 5], [scheduleDay('2026-10-09 23:50'), scheduleDay('2026-10-07 07:00')], scheduleDay('2026-10-10 12:00'));

    expect($missed)->toBe([]);
});

it('WT-004 marks nothing missed without a schedule or without a weigh-in', function () {
    expect(WeighInSchedule::missedDays([], [scheduleDay('2026-10-01 07:00')], scheduleDay('2026-10-10 12:00')))->toBe([])
        ->and(WeighInSchedule::missedDays([1, 2, 3], [], scheduleDay('2026-10-10 12:00')))->toBe([]);
});

it('WT-006 does not mark an unscheduled day missed, and a weigh-in on one leaves the scheduled days as they were', function () {
    // Schedule is Mondays and Fridays only; the extra Wednesday reading is not a scheduled day.
    $missed = WeighInSchedule::missedDays([1, 5], [scheduleDay('2026-10-07 07:00'), scheduleDay('2026-10-06 07:00')], scheduleDay('2026-10-10 12:00'));

    expect(scheduleDates($missed))->toBe(['2026-10-09 00:00']);
});
