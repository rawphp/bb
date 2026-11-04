<?php

use App\Capabilities\Reads\WeighIns\WeighInWeekWords;

function weekWordsDay(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

function weekWordsTitle(string $start, string $end, string $today = '2026-11-04'): string
{
    return WeighInWeekWords::title(weekWordsDay($start), weekWordsDay($end), weekWordsDay($today));
}

it('WT-008 names the week under way This week, and any other by its first and last day', function () {
    expect(weekWordsTitle('2026-10-31', '2026-11-06'))->toBe('This week')
        ->and(weekWordsTitle('2026-11-04', '2026-11-10'))->toBe('This week')
        ->and(weekWordsTitle('2026-10-24', '2026-10-30'))->toBe('24 – 30 Oct')
        ->and(weekWordsTitle('2026-09-26', '2026-10-02'))->toBe('26 Sep – 2 Oct')
        ->and(weekWordsTitle('2026-10-21', '2026-10-21'))->toBe('21 Oct');
});

it('WT-008 adds the year to a week outside this year', function () {
    expect(weekWordsTitle('2025-10-04', '2025-10-10'))->toBe('4 – 10 Oct 2025')
        ->and(weekWordsTitle('2025-12-27', '2026-01-02'))->toBe('27 Dec 2025 – 2 Jan 2026')
        ->and(weekWordsTitle('2026-01-03', '2026-01-09'))->toBe('3 – 9 Jan');
});

it('WT-008 words the week\'s trend in the user\'s unit, and nothing without one', function () {
    expect(WeighInWeekWords::trend(81.84, 'kg'))->toBe('Trend 81.8 kg')
        ->and(WeighInWeekWords::trend(90.72, 'lb'))->toBe('Trend 200.0 lb')
        ->and(WeighInWeekWords::trend(null, 'kg'))->toBe('');
});

it('WT-008 words the change in trend from the week before, to a tenth in the user\'s unit, in an info pill', function () {
    expect(WeighInWeekWords::change(0.26, 'kg')?->toArray())->toBe(['text' => '+0.3 kg on last week', 'tone' => 'info'])
        ->and(WeighInWeekWords::change(-0.2, 'kg')?->text)->toBe('−0.2 kg on last week')
        ->and(WeighInWeekWords::change(-0.454, 'lb')?->text)->toBe('−1.0 lb on last week')
        ->and(WeighInWeekWords::change(0.04, 'kg')?->text)->toBe('Same as last week')
        ->and(WeighInWeekWords::change(-0.04, 'kg')?->text)->toBe('Same as last week')
        ->and(WeighInWeekWords::change(null, 'kg'))->toBeNull();
});
