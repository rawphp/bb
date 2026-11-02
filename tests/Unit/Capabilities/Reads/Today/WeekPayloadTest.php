<?php

use App\Capabilities\Reads\Today\WeekPayload;
use App\Support\Today\TodaySnapshot;
use Tests\Unit\Capabilities\Reads\Today\TodayWorld as W;

// The week strip (rp-clone TodayWeekStripTests), from in-memory rows.

function weekPayload(TodaySnapshot $snapshot): array
{
    return WeekPayload::build($snapshot)->toArray();
}

it('SCH-020 shows the 7 days from the plan\'s weekday with their calories', function () {
    $payload = weekPayload(W::snapshot());

    expect($payload['week_start'])->toBe('2026-09-19')
        ->and(array_column($payload['days'], 'letter'))->toBe(['S', 'S', 'M', 'T', 'W', 'T', 'F'])
        ->and(array_column($payload['days'], 'number'))->toBe(['19', '20', '21', '22', '23', '24', '25'])
        ->and(array_column($payload['days'], 'calories_text'))->toBe(['2200', '2200', '2400', '2200', '2600', '2200', '2400']);
});

it('SCH-020 marks today and the selected day', function () {
    $marked = fn (array $payload, string $flag) => array_column(array_filter($payload['days'], fn ($day) => $day[$flag]), 'number');
    $monday = weekPayload(W::snapshot(date: '2026-09-21'));

    expect($marked(weekPayload(W::snapshot()), 'is_today'))->toBe(['23'])
        ->and($marked(weekPayload(W::snapshot()), 'is_selected'))->toBe(['23'])
        ->and($marked($monday, 'is_selected'))->toBe(['21'])
        ->and($marked($monday, 'is_today'))->toBe(['23']);
});

it('SCH-020 swiping keeps the weekday and stops at the ends of the plan', function () {
    $middle = weekPayload(W::snapshot());
    $first = weekPayload(W::snapshot(date: '2026-09-14'));
    $last = weekPayload(W::snapshot(date: '2026-10-06'));

    expect([$middle['previous_week'], $middle['next_week']])->toBe(['2026-09-16', '2026-09-30'])
        ->and(weekPayload(W::snapshot(date: '2026-09-30'))['heading'])->toBe('WEEK 3')
        ->and($first['previous_week'])->toBeNull()
        ->and($first['days'][0]['number'])->toBe('12')
        ->and(weekPayload(W::snapshot(date: '2026-09-19'))['previous_week'])->toBe('2026-09-12')
        ->and($last['next_week'])->toBeNull()
        ->and($last['days'][0]['number'])->toBe('3');
});

it('SCH-020 ONB-031 before the plan, the weeks from today up to the start can be browsed', function () {
    $payload = weekPayload(W::snapshot(today: '2026-09-10'));

    expect($payload['previous_week'])->toBeNull()
        ->and($payload['next_week'])->toBe('2026-09-17')
        ->and(weekPayload(W::snapshot(today: '2026-09-10', date: '2026-09-15'))['previous_week'])->toBe('2026-09-10');
});

it('SCH-021 a finished day eaten on target shows the green dot', function () {
    $dots = array_column(weekPayload(W::snapshot())['days'], 'is_complete', 'number');

    // Monday was well off target, today is not finished, and nothing was eaten on the Saturday.
    expect($dots['22'])->toBeTrue()
        ->and($dots['21'])->toBeFalse()
        ->and($dots['23'])->toBeFalse()
        ->and($dots['19'])->toBeFalse();
});

it('SCH-021 days before the plan start show a dash', function () {
    $payload = weekPayload(W::snapshot(today: '2026-09-10'));

    expect(array_column($payload['days'], 'is_before_plan'))->toBe(array_fill(0, 7, true))
        ->and(array_unique(array_column($payload['days'], 'calories_text')))->toBe(['–'])
        ->and(array_filter(array_column($payload['days'], 'is_complete')))->toBe([])
        ->and(array_filter(array_column($payload['days'], 'has_training')))->toBe([])
        ->and($payload['heading'])->toBeNull()
        ->and(array_column(weekPayload(W::snapshot(today: '2026-09-10', date: '2026-09-12'))['days'], 'is_before_plan'))->toBe(array_fill(0, 7, false));
});

it('SCH-022 training days carry the dumbbell', function () {
    $training = array_column(array_filter(weekPayload(W::snapshot())['days'], fn ($day) => $day['has_training']), 'number');

    expect(array_values($training))->toBe(['21', '23', '25']);
});

it('SCH-022 SCH-053 a skipped session leaves no dumbbell', function () {
    $sessions = [...W::sessions(), W::session('w2d3', 2, 3, skipped: true)];
    $training = array_column(array_filter(weekPayload(W::snapshot(sessions: $sessions))['days'], fn ($day) => $day['has_training']), 'number');

    expect(array_values($training))->toBe(['21', '23']);
});

it('SCH-022 a deload week shows the Deload label above the strip', function () {
    $normal = weekPayload(W::snapshot());
    $deload = weekPayload(W::snapshot(date: '2026-10-06'));

    expect($normal['heading'])->toBe('WEEK 2')
        ->and($normal['pills'])->toBe([])
        ->and($deload['heading'])->toBe('WEEK 4')
        ->and($deload['pills'])->toBe([['text' => 'Deload', 'tone' => 'neutral']]);
});

it('SCH-022 SCH-060 an away week shows the Away pill', function () {
    expect(weekPayload(W::snapshot(date: '2026-09-28'))['pills'])->toBe([['text' => 'Away', 'tone' => 'info']]);
});

it('SCH-023 the month view offers the plan\'s dates', function () {
    expect(weekPayload(W::snapshot())['calendar'])->toBe(['first' => '2026-09-12', 'last' => '2026-10-09'])
        ->and(weekPayload(W::snapshot(withPlan: false))['calendar'])->toBeNull();
});

it('SCH-023 a date picked in the month view opens its week', function () {
    $payload = weekPayload(W::snapshot(date: '2026-10-06'));

    expect($payload['week_start'])->toBe('2026-10-03')
        ->and(array_column(array_filter($payload['days'], fn ($day) => $day['is_selected']), 'date'))->toBe(['2026-10-06']);
});

it('SCH-020 after the plan\'s last target, today\'s week opens but swipes stay inside the plan', function () {
    $payload = weekPayload(W::snapshot(today: '2026-10-25'));

    expect($payload['week_start'])->toBe('2026-10-24')
        ->and($payload['previous_week'])->toBeNull()
        ->and($payload['next_week'])->toBeNull()
        ->and(weekPayload(W::snapshot(today: '2026-10-25', date: '2026-10-02'))['next_week'])->toBe('2026-10-09');
});
