<?php

use Tests\Unit\Capabilities\Reads\Progress\ProgressWorld as W;

// Phase history, mesocycle history and the exercise list (rp-clone ProgressModelTests), from in-memory rows.

it('ANA-006 lists past phases with their dates, weight change and rate', function () {
    $history = W::payload(W::snapshot())['phase_history'];

    // Weigh-ins only begin on 1 September, after the phase ended.
    expect($history)->toHaveCount(1)
        ->and($history[0])->toBe([
            'id' => W::HOLD,
            'title' => 'Maintenance',
            'dates_label' => '4 Aug 2026 – 31 Aug 2026',
            'lines' => [
                ['label' => 'Weight change', 'value' => '–', 'tone' => 'neutral'],
                ['label' => 'Average rate', 'value' => '–', 'tone' => 'neutral'],
            ],
            'mesocycles' => [['label' => 'Base', 'value' => '4 weeks', 'tone' => 'neutral']],
        ]);
});

it('ANA-006 signs the weight change and the weekly rate', function () {
    // One steady reading on the phase\'s last day: the trend starts and ends at 80 kg.
    $flat = W::payload(W::snapshot(weighIns: [W::weighIn('2026-08-31 07:00:00', 80)]))['phase_history'][0]['lines'];
    $down = W::payload(W::snapshot(weighIns: array_map(
        fn (int $i) => W::weighIn('2026-08-'.sprintf('%02d', $i + 4).' 07:00:00', 80 - $i * 0.5), range(0, 27),
    )))['phase_history'][0]['lines'];

    expect(array_column($flat, 'value'))->toBe(['+0.0 kg', '+0.00% per week'])
        ->and($down[0]['value'])->toMatch('/^-[0-9]+\.[0-9] kg$/')
        ->and($down[1]['value'])->toMatch('/^-[0-9]\.[0-9]{2}% per week$/');
});

it('ANA-006 shows the change in pounds for a pounds user', function () {
    $lines = W::payload(W::snapshot(weighIns: [W::weighIn('2026-08-31 07:00:00', 80)], profile: ['weight_unit' => 'lb']))['phase_history'][0]['lines'];

    expect($lines[0]['value'])->toBe('+0.0 lb');
});

it('ANA-008 a phase\'s mesocycles are its own, without the draft', function () {
    $history = W::payload(W::snapshot(
        phases: [W::phase(W::HOLD, 'maintenance', '2026-08-04', '2026-09-01', 0)],
    ))['phase_history'];

    expect($history[0]['mesocycles'])->toBe([['label' => 'Base', 'value' => '4 weeks', 'tone' => 'neutral']]);
});

it('ANA-008 names a mesocycle with a week count and a mesocycle with no name', function () {
    $snapshot = W::snapshot(mesocycles: [
        W::mesocycle(W::BASE, null, W::HOLD, '2026-08-04', 1),
        W::mesocycle(W::CUT_A, 'Cut A', W::HOLD, '2026-08-11', 3),
    ]);

    expect(W::payload($snapshot)['phase_history'][0]['mesocycles'])->toBe([
        ['label' => 'Mesocycle', 'value' => '1 week', 'tone' => 'neutral'],
        ['label' => 'Cut A', 'value' => '3 weeks', 'tone' => 'neutral'],
    ]);
});

it('ANA-006 newest phase first', function () {
    $phases = [
        W::phase(W::HOLD, 'maintenance', '2026-08-04', '2026-09-01', 0),
        W::phase(W::CUT, 'fatLoss', '2026-09-01', '2026-10-05', -0.5),
    ];

    expect(array_column(W::payload(W::snapshot(phases: $phases))['phase_history'], 'title'))->toBe(['Fat loss', 'Maintenance']);
});

it('ANA-007 averages the weekly sets from the logged sets', function () {
    $history = W::payload(W::snapshot())['mesocycle_history'];

    // Cut B (to 10 Nov) is still running; Cut A ended 28 September and Base on 31 August.
    expect(array_column($history, 'title'))->toBe(['Cut A', 'Base'])
        ->and($history[0])->toBe([
            'id' => W::CUT_A,
            'title' => 'Cut A',
            'dates_label' => '1 Sep 2026 – 28 Sep 2026',
            'phase' => ['text' => 'Fat loss', 'tone' => 'info'],
            'sets_title' => 'Average sets per week',
            // Chest: 5 logged sets (the unlogged one and the other mesocycle's do not count) over 2 weeks.
            'sets' => [
                ['label' => 'Chest', 'value' => '2.5', 'tone' => 'neutral'],
                ['label' => 'Biceps', 'value' => '0.5', 'tone' => 'neutral'],
            ],
        ])
        ->and($history[1]['phase'])->toBe(['text' => 'Maintenance', 'tone' => 'info'])
        ->and($history[1]['dates_label'])->toBe('4 Aug 2026 – 31 Aug 2026');
});

it('ANA-007 says so when a mesocycle has no sets logged, and shows no phase when it ran in none', function () {
    $history = W::payload(W::snapshot(mesocycles: [W::mesocycle(W::BASE, 'Solo', null, '2026-08-04', 2)]))['mesocycle_history'];

    expect($history[0]['sets_title'])->toBe('No sets logged')
        ->and($history[0]['sets'])->toBe([])
        ->and($history[0]['phase'])->toBeNull();
});

it('ANA-007 a set without an exercise is ignored', function () {
    // Week 3 of Cut A has only a set whose exercise was deleted: it must not add a week or a muscle.
    $snapshot = W::snapshot(
        sessions: [W::session('s-a', W::CUT_A, 1), W::session('s-b', W::CUT_A, 2), W::session('s-c3', W::CUT_A, 3)],
        sets: [W::set('1', 's-a', 12), W::set('2', 's-b', 12), W::set('9', 's-c3', null)],
    );

    $cutA = W::payload($snapshot)['mesocycle_history'][0];

    expect($cutA['title'])->toBe('Cut A')
        ->and($cutA['sets'])->toBe([['label' => 'Chest', 'value' => '1.0', 'tone' => 'neutral']]);
});

it('ANA-007 WO-025 a logged set that was skipped is not a working set, and a week of only skipped sets adds no week', function () {
    // Week 1: one working set and one logged-and-skipped set. Week 3: only a skipped set.
    $snapshot = W::snapshot(
        sessions: [W::session('s-a', W::CUT_A, 1), W::session('s-c3', W::CUT_A, 3)],
        sets: [W::set('1', 's-a', 12), W::set('2', 's-a', 12, skipped: true), W::set('3', 's-c3', 12, skipped: true)],
    );

    expect(W::payload($snapshot)['mesocycle_history'][0]['sets'])->toBe([['label' => 'Chest', 'value' => '1.0', 'tone' => 'neutral']]);
});

it('ANA-007 a set of an exercise the user cannot see is ignored', function () {
    $snapshot = W::snapshot(
        sessions: [W::session('s-a', W::CUT_A, 1)],
        sets: [W::set('1', 's-a', 12), W::set('2', 's-a', 999)],
    );

    expect(W::payload($snapshot)['mesocycle_history'][0]['sets'])->toBe([['label' => 'Chest', 'value' => '1.0', 'tone' => 'neutral']]);
});

it('ANA-007 a mesocycle ended early is past from its end', function () {
    // Cut B ran 6 weeks from 29 September; it was ended on 6 October, after one week.
    $snapshot = W::snapshot(mesocycles: [W::mesocycle(W::CUT_B, 'Cut B', W::CUT, '2026-09-29', 6, endedOn: '2026-10-06')]);

    $history = W::payload($snapshot)['mesocycle_history'];

    expect($history[0]['dates_label'])->toBe('29 Sep 2026 – 5 Oct 2026');
});

it('ANA-009 lists the exercises with a logged set, most recently used first', function () {
    $exercises = W::payload(W::snapshot())['exercises'];

    // Squat has never been used; the rest by last use: Row 9 Oct, Bench 8 Oct, Curl 5 Oct.
    expect($exercises)->toBe([
        ['id' => '25', 'name' => 'Barbell Row', 'muscle' => 'Back'],
        ['id' => '12', 'name' => 'Bench Press', 'muscle' => 'Chest'],
        ['id' => '20', 'name' => 'Curl', 'muscle' => 'Biceps'],
    ]);
});

it('EX-013 leaves out an exercise the user cannot see even if a set names it', function () {
    $exercises = W::payload(W::snapshot(lastUsed: [12 => '2026-10-08 07:30:00', 999 => '2026-10-09 07:30:00']))['exercises'];

    expect(array_column($exercises, 'id'))->toBe(['12']);
});
