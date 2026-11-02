<?php

use Tests\Unit\Capabilities\Reads\Progress\ProgressWorld as W;

// The This week, review and steps cards (rp-clone ProgressModelTests), from in-memory rows.

const PROGRESS_WEEK = ['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'];

// MARK: ANA-003 This week

it('ANA-003 sums the week\'s targets and the consumed so far', function () {
    $card = W::payload(W::snapshot(targetDays: PROGRESS_WEEK, consumed: ['2026-10-10' => [1500, 110]]))['this_week'];

    expect($card['title'])->toBe('This week')
        ->and($card['edit_schedule_title'])->toBe('Edit schedule')
        ->and(array_column($card['days'], 'date'))->toBe(PROGRESS_WEEK)
        ->and($card['calories'])->toBe([
            'title' => 'Calories this week', 'short_label' => 'Cal', 'value_label' => '1500/14000', 'fraction' => 0.11,
        ]);
});

it('ANA-003 with nothing eaten consumed is zero', function () {
    $calories = W::payload(W::snapshot(targetDays: PROGRESS_WEEK))['this_week']['calories'];

    expect($calories['value_label'])->toBe('0/14000')
        ->and($calories['fraction'])->toBe(0.0);
});

it('ANA-003 targets outside the current week are not counted', function () {
    $calories = W::payload(W::snapshot(targetDays: ['2026-10-09', '2026-10-10', '2026-10-16', '2026-10-17']))['this_week']['calories'];

    expect($calories['value_label'])->toBe('0/4000');
});

it('ANA-003 counts only what was eaten up to today and never more than a full bar', function () {
    $calories = W::payload(W::snapshot(
        targetDays: PROGRESS_WEEK, consumed: ['2026-10-10' => [30000, 100], '2026-10-12' => [5000, 100]],
    ))['this_week']['calories'];

    expect($calories['value_label'])->toBe('30000/14000')
        ->and($calories['fraction'])->toBe(1.0);
});

it('ANA-003 the week follows the review weekday', function () {
    // Review day Monday: on Saturday 10 October the week is 5 to 11 October.
    $days = W::payload(W::snapshot(reviewWeekday: 1))['this_week']['days'];

    expect($days[0]['date'])->toBe('2026-10-05')
        ->and($days[6]['date'])->toBe('2026-10-11');
});

it('SAFE-003 a week with no calorie target shows no calories line', function () {
    // Training-only mode has no day targets, so "Calories this week 0/0" would be a target the user never had.
    $card = W::payload(W::snapshot())['this_week'];

    expect($card['calories'])->toBeNull()
        ->and($card['days'])->toHaveCount(7);
});

it('SAFE-003 a week with calorie targets shows the calories line', function () {
    expect(W::payload(W::snapshot(targetDays: PROGRESS_WEEK))['this_week']['calories'])->not->toBeNull();
});

it('ANA-003 with no plan there is no week card and no review card', function () {
    $payload = W::payload(W::snapshot(withPlan: false, phases: [], mesocycles: []));

    expect($payload['this_week'])->toBeNull()
        ->and($payload['review_card'])->toBeNull();
});

// MARK: ANA-011 the week strip

it('ANA-011 shows each day with its letter, number and target calories', function () {
    $days = W::payload(W::snapshot(targetDays: ['2026-10-10', '2026-10-12'], targetKcal: 2049.6))['this_week']['days'];

    expect(array_column($days, 'letter'))->toBe(['S', 'S', 'M', 'T', 'W', 'T', 'F'])
        ->and(array_column($days, 'day_label'))->toBe(['10', '11', '12', '13', '14', '15', '16'])
        ->and(array_column($days, 'calories_label'))->toBe(['2050', null, '2050', null, null, null, null])
        ->and(array_column($days, 'is_today'))->toBe([true, false, false, false, false, false, false]);
});

it('ANA-011 green dots on finished days within ten percent', function () {
    // Tuesday 13 October: the week is Saturday 10 to Friday 16.
    $payload = W::payload(W::snapshot(
        today: '2026-10-13', targetDays: PROGRESS_WEEK,
        consumed: ['2026-10-10' => [2050, 145], '2026-10-11' => [1000, 145], '2026-10-13' => [2000, 150]],
    ))['this_week'];

    expect(array_column($payload['days'], 'is_complete'))->toBe([
        true,   // Sat: on target
        false,  // Sun: far under
        false,  // Mon: nothing logged
        false,  // Tue: today never gets one
        false, false, false,
    ])
        ->and(array_column($payload['days'], 'is_today'))->toBe([false, false, false, true, false, false, false])
        ->and($payload['calories']['value_label'])->toBe('5050/14000');
});

it('ANA-011 no consumed data means no dots', function () {
    $days = W::payload(W::snapshot(today: '2026-10-14', targetDays: PROGRESS_WEEK))['this_week']['days'];

    expect(array_filter(array_column($days, 'is_complete')))->toBe([]);
});

it('ANA-011 days before the plan start get no dot and no calories', function () {
    $days = W::payload(W::snapshot(
        today: '2026-10-14', planStart: '2026-10-12', targetDays: ['2026-10-10'], consumed: ['2026-10-10' => [2000, 150]],
    ))['this_week']['days'];

    expect(array_column($days, 'is_complete'))->toBe([false, false, false, false, false, false, false]);
});

// MARK: ANA-004 review card

it('ANA-004 REV-001 shows the review card on the review day with two weigh-ins in the week that ended', function () {
    expect(W::payload(W::snapshot())['review_card'])->toBe(['title' => 'Weekly review available', 'action_title' => 'Open']);
});

it('REV-002 the card stays on the days after the review day', function () {
    // Friday 9 October is day 7 of the week that began on Saturday the 3rd.
    expect(W::payload(W::snapshot(today: '2026-10-09'))['review_card'])->not->toBeNull();
});

it('REV-001 no card before the first week has ended, then the short first week can be reviewed', function () {
    // The plan starts on Wednesday 7 October; its first week runs to Friday the 9th.
    $weighIns = W::weighIns('2026-10-07', 4);

    $first = W::payload(W::snapshot(today: '2026-10-08', weighIns: $weighIns, planStart: '2026-10-07'));
    $second = W::payload(W::snapshot(today: '2026-10-10', weighIns: $weighIns, planStart: '2026-10-07'));

    expect($first['review_card'])->toBeNull()
        ->and($second['review_card'])->not->toBeNull();
});

it('REV-001 no card once the week is reviewed', function () {
    $snapshot = W::snapshot(planWeeks: [W::planWeek('2026-10-03', reviewedAt: '2026-10-10 07:00:00')]);

    expect(W::payload($snapshot)['review_card'])->toBeNull();
});

it('SCH-061 an away week needs no weigh-ins for the card', function () {
    $snapshot = W::snapshot(weighIns: W::weighIns('2026-09-10', 5), planWeeks: [W::planWeek('2026-10-03', away: true, awayTraining: 'pause')]);

    expect(W::payload($snapshot)['review_card'])->not->toBeNull();
});

it('ANA-004 no review card with fewer than two weigh-ins in the week', function () {
    // The only weigh-ins are 3 weeks old.
    expect(W::payload(W::snapshot(weighIns: W::weighIns('2026-09-10', 5)))['review_card'])->toBeNull();
});

// MARK: ANA-005 steps

it('ANA-005 hides the steps without step data', function () {
    expect(W::payload(W::snapshot())['steps'])->toBeNull();
});

it('ANA-005 shows steps against today\'s range', function () {
    // Saturday's range is 6000 to 8000.
    expect(W::payload(W::snapshot(steps: 4500))['steps'])->toBe([
        'title' => 'Steps',
        'bar' => ['title' => 'Steps today', 'short_label' => 'Steps', 'value_label' => '4,500/8,000', 'fraction' => 0.56],
        'target_label' => 'Target 6,000 to 8,000',
    ]);
});

it('ANA-005 a day with no steps yet is still shown, and a full day is a full bar', function () {
    $none = W::payload(W::snapshot(steps: 0))['steps']['bar'];
    $over = W::payload(W::snapshot(steps: 9000))['steps']['bar'];

    expect($none['value_label'])->toBe('0/8,000')
        ->and($none['fraction'])->toBe(0.0)
        ->and($over['value_label'])->toBe('9,000/8,000')
        ->and($over['fraction'])->toBe(1.0);
});

it('ANA-005 hides the steps on a weekday with no range', function () {
    // Sunday 11 October: the plan has ranges for Saturday and Tuesday only.
    expect(W::payload(W::snapshot(today: '2026-10-11', steps: 5000))['steps'])->toBeNull();
});
