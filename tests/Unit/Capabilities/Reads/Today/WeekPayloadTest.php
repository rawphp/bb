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

it('SCH-020 SCH-023 a date opens in a week of the plan or in today\'s week; any other goes to the nearest plan edge', function () {
    $snapshot = W::snapshot(today: '2026-10-25');
    $day = fn (string $date) => $snapshot->insidePlan(new DateTimeImmutable($date))->format('Y-m-d');

    expect($day('2026-10-05'))->toBe('2026-10-05')
        ->and($day('2026-10-26'))->toBe('2026-10-26')
        // Between the plan's last week and today's week: no swipe leads in or out, so it goes to the last target.
        ->and($day('2026-10-19'))->toBe('2026-10-09')
        ->and($day('2026-12-25'))->toBe('2026-10-09')
        ->and($day('2026-01-01'))->toBe('2026-09-12');
});

// SCH-063: the rough week's pill and the menu of the strip.

function weekActions(array $payload): array
{
    return array_column($payload['actions'], 'label', 'action');
}

it('SCH-063 a rough week shows the Rough week pill after Deload and Away', function () {
    $rough = weekPayload(W::snapshot(planWeeks: [W::planWeek('2026-09-19', rough: true)]));
    $all = weekPayload(W::snapshot(date: '2026-09-28', planWeeks: [W::planWeek('2026-09-26', deload: true, away: true, rough: true)]));

    expect($rough['pills'])->toBe([['text' => 'Rough week', 'tone' => 'warning']])
        ->and($all['pills'])->toBe([
            ['text' => 'Deload', 'tone' => 'neutral'], ['text' => 'Away', 'tone' => 'info'], ['text' => 'Rough week', 'tone' => 'warning'],
        ])
        ->and(weekPayload(W::snapshot())['pills'])->toBe([]);
});

it('SCH-063 this plan week offers Mark rough week, or Unmark when it is already rough', function () {
    expect(weekActions(weekPayload(W::snapshot())))->toBe(['mark_rough' => 'Mark rough week'])
        ->and(weekActions(weekPayload(W::snapshot(planWeeks: [W::planWeek('2026-09-19', rough: true)]))))->toBe(['unmark_rough' => 'Unmark rough week']);
});

it('SCH-063 a deload or an away week of this plan week offers no rough menu', function () {
    expect(weekPayload(W::snapshot(planWeeks: [W::planWeek('2026-09-19', deload: true)]))['actions'])->toBe([])
        ->and(weekPayload(W::snapshot(planWeeks: [W::planWeek('2026-09-19', away: true)]))['actions'])->toBe([]);
});

it('SCH-063 only the week of today offers a rough menu, not a past or later week', function () {
    expect(weekPayload(W::snapshot(date: '2026-09-15'))['actions'])->toBe([])
        ->and(weekPayload(W::snapshot(date: '2026-09-28', planWeeks: []))['actions'])->toBe([]);
});

// SCH-060: the same strip offers the weeks the Away sheet would.

it('SCH-060 a later week of the phase offers Mark week away', function () {
    $payload = weekPayload(W::snapshot(date: '2026-09-30', planWeeks: [], phases: [W::phase()]));

    expect(weekActions($payload))->toBe(['mark_away' => 'Mark week away']);
});

it('SCH-060 an away week offers Edit away week', function () {
    $payload = weekPayload(W::snapshot(date: '2026-09-28', phases: [W::phase()]));

    expect(weekActions($payload))->toBe(['edit_away' => 'Edit away week']);
});

it('SCH-060 the week in progress, past weeks and weeks past the phase offer no away menu', function () {
    $phase = W::phase(end: '2026-10-03');

    expect(weekActions(weekPayload(W::snapshot(phases: [W::phase()]))))->toBe(['mark_rough' => 'Mark rough week'])
        ->and(weekPayload(W::snapshot(date: '2026-09-15', phases: [W::phase()]))['actions'])->toBe([])
        // The phase ends on 2026-10-03, so its last week starts on 2026-09-26.
        ->and(weekActions(weekPayload(W::snapshot(date: '2026-09-28', planWeeks: [], phases: [$phase]))))->toBe(['mark_away' => 'Mark week away'])
        ->and(weekActions(weekPayload(W::snapshot(date: '2026-10-06', planWeeks: [], phases: [$phase]))))->toBe([])
        ->and(weekActions(weekPayload(W::snapshot(date: '2026-09-30', planWeeks: [], phases: []))))->toBe([]);
});

/** Hard Other activities on these days. */
function hardOn(string ...$dates): array
{
    return array_map(fn (int $i, string $date) => W::activity("dddddddd-0000-4000-8000-00000000000{$i}", $date, intensity: 'hard'), array_keys($dates), $dates);
}

it('SCH-062 PLAN-009 the phase on the strip\'s last day decides the cardio warning', function () {
    $hard = hardOn('2026-09-19', '2026-09-20', '2026-09-21', '2026-09-22', '2026-09-24');
    $gainThenCut = [W::phase('muscleGain', end: '2026-09-22'), W::phase('miniCut', start: '2026-09-22')->forceFill(['id' => 'cccccccc-0000-4000-8000-000000000002'])];
    $cutThenGain = [W::phase('fatLoss', end: '2026-09-22'), W::phase('muscleGain', start: '2026-09-22')->forceFill(['id' => 'cccccccc-0000-4000-8000-000000000002'])];

    expect(weekPayload(W::snapshot(activities: $hard, phases: $gainThenCut))['warnings'])->toBe([[
        'text' => 'Too much cardio hurts lifting recovery. That makes 5 hard sessions this week; more than 4 is a lot during fat loss.',
        'tone' => 'warning',
    ]])
        ->and(weekPayload(W::snapshot(activities: $hard, phases: $cutThenGain))['warnings'])->toBe([]);
});

it('SCH-062 counts only the hard Other activities on the strip\'s 7 days', function () {
    $hard = hardOn('2026-09-18', '2026-09-19', '2026-09-21', '2026-09-23', '2026-09-25', '2026-09-26');

    expect(weekPayload(W::snapshot(activities: $hard, phases: [W::phase()]))['warnings'])->toBe([]);
});
