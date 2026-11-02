<?php

use App\Capabilities\Reads\Train\TrainPayload;
use App\Capabilities\Reads\Train\TrainSnapshot;
use Tests\Unit\Capabilities\Reads\Train\TrainWorld as W;

// The Train tab (rp-clone TrainModel, MesocycleListModel and TodayModel+NextMesocycle), from in-memory rows.

function train(TrainSnapshot $snapshot): array
{
    return TrainPayload::build($snapshot)->toArray();
}

/** rp-clone MesocycleFixtures: today is Saturday 10 October. */
function listWorld(array $mesocycles): TrainSnapshot
{
    return W::snapshot(
        today: '2026-10-10',
        mesocycles: $mesocycles,
        phases: [
            W::phase(W::CUT, 'fatLoss', '2026-09-28', '2026-11-09'),
            W::phase(W::GAIN, 'muscleGain', '2026-11-09', null),
        ],
        sessions: [],
        sets: [],
    );
}

it('MESO-001 lists current, then planned soonest first, then past most recent first', function () {
    $payload = train(listWorld([
        W::mesocycle(['id' => 'old', 'name' => 'Old block', 'start_date' => '2026-06-01', 'weeks' => 4, 'phase_id' => null]),
        W::mesocycle(['id' => 'b2', 'name' => 'Block C', 'start_date' => '2026-12-07', 'weeks' => 4]),
        W::mesocycle(['id' => 'a', 'name' => 'Block A', 'start_date' => '2026-10-05', 'weeks' => 5, 'phase_id' => W::CUT]),
        W::mesocycle(['id' => 'b', 'name' => 'Block B', 'start_date' => '2026-11-09', 'weeks' => 4]),
        W::mesocycle(['id' => 'older', 'name' => 'Older block', 'start_date' => '2026-03-02', 'weeks' => 4]),
    ]));

    expect(array_column($payload['sections'], 'status'))->toBe(['current', 'planned', 'past'])
        ->and(array_column($payload['sections'], 'title'))->toBe(['Current', 'Planned', 'Past'])
        ->and(array_map(fn ($section) => array_column($section['rows'], 'name'), $payload['sections']))
        ->toBe([['Block A'], ['Block B', 'Block C'], ['Old block', 'Older block']]);
});

it('MESO-001 each row shows its dates, length and phase', function () {
    $payload = train(listWorld([
        W::mesocycle(['id' => 'a', 'name' => 'Block A', 'start_date' => '2026-10-05', 'weeks' => 5, 'phase_id' => W::CUT]),
        W::mesocycle(['id' => 'short', 'name' => 'Short', 'start_date' => '2026-11-09', 'weeks' => 1]),
        W::mesocycle(['id' => 'none', 'name' => 'Loose', 'start_date' => '2026-11-16', 'weeks' => 4, 'phase_id' => null]),
    ]));

    expect($payload['sections'][0]['rows'][0])->toBe([
        'id' => 'a', 'name' => 'Block A', 'dates_text' => '5 Oct – 8 Nov 2026', 'summary_text' => '5 weeks · Fat loss',
    ])
        ->and(array_column($payload['sections'][1]['rows'], 'summary_text'))->toBe(['1 week · Muscle gain', '4 weeks · No phase']);
});

it('MESO-001 MESO-007 keeps the draft out of the list and offers to resume it, warning that a new build replaces it', function () {
    $payload = train(listWorld([
        W::mesocycle(['id' => 'a', 'name' => 'Block A', 'start_date' => '2026-10-05']),
        W::mesocycle(['id' => 'draft', 'name' => 'Next block', 'is_draft' => true, 'start_date' => null, 'weeks' => null]),
    ]));

    expect(array_column($payload['sections'][0]['rows'], 'id'))->toBe(['a'])
        ->and($payload['draft'])->toBe([
            'id' => 'draft',
            'name' => 'Next block',
            'text' => 'Saved as a draft. Tap to continue building.',
            'replace_warning' => 'You have an unfinished mesocycle. Starting a new one replaces it.',
        ])
        ->and(train(listWorld([]))['draft'])->toBeNull()
        ->and(train(listWorld([]))['sections'])->toBe([]);
});

it('MESO-043 an ended mesocycle is past and ends the day before it was ended', function () {
    $ended = train(listWorld([W::mesocycle(['id' => 'a', 'start_date' => '2026-10-05', 'ended_on' => '2026-10-10'])]));
    $endsLater = train(listWorld([W::mesocycle(['id' => 'a', 'start_date' => '2026-10-05', 'ended_on' => '2026-10-15'])]));

    expect($ended['sections'][0]['status'])->toBe('past')
        ->and($ended['sections'][0]['rows'][0]['dates_text'])->toBe('5 Oct – 9 Oct 2026')
        ->and($ended['sections'][0]['rows'][0]['summary_text'])->toBe('5 weeks · Muscle gain')
        ->and($endsLater['sections'][0]['status'])->toBe('current')
        ->and($endsLater['sections'][0]['rows'][0]['dates_text'])->toBe('5 Oct – 14 Oct 2026');
});

it('MESO-002 offers four routes in order', function () {
    $routes = train(listWorld([]))['create_routes'];

    expect(array_column($routes, 'route'))->toBe(['template', 'copy_previous', 'builder', 'scratch'])
        ->and(array_column($routes, 'title'))->toBe(['Template', 'Copy previous mesocycle', 'Meso Builder', 'Build from scratch'])
        ->and(array_column($routes, 'available'))->toBe([true, false, true, true])
        ->and(array_column($routes, 'copy_source_id'))->toBe([null, null, null, null]);
});

it('MESO-002 copy is available once a mesocycle is saved, and copies the latest started, else the latest planned', function () {
    $started = train(listWorld([
        W::mesocycle(['id' => 'old', 'start_date' => '2026-06-01']),
        W::mesocycle(['id' => 'a', 'start_date' => '2026-10-05']),
        W::mesocycle(['id' => 'b', 'start_date' => '2026-11-09']),
        W::mesocycle(['id' => 'draft', 'is_draft' => true, 'start_date' => '2026-12-07']),
    ]));
    $planned = train(listWorld([
        W::mesocycle(['id' => 'b', 'start_date' => '2026-11-09']),
        W::mesocycle(['id' => 'c', 'start_date' => '2026-12-07']),
    ]));

    expect($started['create_routes'][1]['available'])->toBeTrue()
        ->and($started['create_routes'][1]['copy_source_id'])->toBe('a')
        ->and($planned['create_routes'][1]['copy_source_id'])->toBe('c');
});

it('MESO-060 the current mesocycle\'s card shows its week, and no prompt before the deload', function () {
    $current = train(W::snapshot())['current'];

    expect($current)->toMatchArray([
        'id' => W::MESO,
        'name' => 'Meso 2',
        'week_text' => 'Week 4 of 5',
        'phase_title' => 'Muscle gain',
        'dates_text' => '14 Sep – 18 Oct 2026',
        'next_mesocycle' => null,
    ]);
});

it('MESO-060 from the deload week the card prompts to build the next mesocycle, copying this one by default', function () {
    $current = train(W::snapshot(today: '2026-10-12'))['current'];

    expect($current['week_text'])->toBe('Week 5 of 5 · Deload')
        ->and($current['next_mesocycle'])->toBe([
            'starts_text' => 'Starts Monday 19 Oct',
            'start_date' => '2026-10-19',
            'copy_source_id' => W::MESO,
            'template_phase' => 'gain',
            'priority_notice' => null,
            'active_rest' => null,
        ]);
});

it('MESO-060 the prompt stays after the last week until a later mesocycle is saved', function () {
    $over = train(W::snapshot(today: '2026-10-25'));
    $next = train(W::snapshot(today: '2026-10-25', mesocycles: [
        W::mesocycle(),
        W::mesocycle(['id' => 'next', 'start_date' => '2026-10-26']),
    ]));
    $draftOnly = train(W::snapshot(today: '2026-10-25', mesocycles: [
        W::mesocycle(),
        W::mesocycle(['id' => 'draft', 'is_draft' => true, 'start_date' => '2026-10-26']),
    ]));

    expect($over['current']['next_mesocycle']['start_date'])->toBe('2026-10-19')
        ->and($next['current'])->toBeNull()
        ->and($draftOnly['current']['next_mesocycle'])->not->toBeNull();
});

it('MESO-060 MESO-043 a mesocycle ended early prompts at once, starting the plan week after the end', function () {
    $current = train(W::snapshot(today: '2026-10-07', mesocycles: [W::mesocycle(['ended_on' => '2026-10-06'])]))['current'];

    expect($current['id'])->toBe(W::MESO)
        ->and($current['dates_text'])->toBe('14 Sep – 5 Oct 2026')
        ->and($current['next_mesocycle']['start_date'])->toBe('2026-10-12');
});

it('MESO-060 the template filter follows the phase that week 1 of the next mesocycle falls in', function (?array $phase, string $filter) {
    $phases = [W::phase(W::GAIN, 'muscleGain', '2026-07-27', '2026-10-19')];
    if ($phase !== null) {
        $phases[] = W::phase(W::CUT, $phase[0], '2026-10-19', null);
    }

    expect(train(W::snapshot(today: '2026-10-12', phases: $phases))['current']['next_mesocycle']['template_phase'])->toBe($filter);
})->with([
    'fat loss' => [['fatLoss'], 'cut'],
    'mini-cut' => [['miniCut'], 'cut'],
    'maintenance' => [['maintenance'], 'maintain'],
    'no phase' => [null, 'all'],
]);

it('MESO-065 a copy into a phase of another type says the priorities reset', function () {
    $snapshot = W::snapshot(today: '2026-10-12', phases: [
        W::phase(W::GAIN, 'muscleGain', '2026-07-27', '2026-10-19'),
        W::phase(W::CUT, 'fatLoss', '2026-10-19', null),
    ]);
    $payload = train($snapshot);
    $notice = 'Priorities reset for Fat loss. Your old priorities can be kept.';

    expect($payload['current']['next_mesocycle']['priority_notice'])->toBe($notice)
        ->and($payload['create_routes'][1]['priority_notice'])->toBe($notice)
        ->and(train(W::snapshot(today: '2026-10-12'))['create_routes'][1]['priority_notice'])->toBeNull();
});

it('MESO-064 two flat weeks running recommend an active rest', function () {
    $flat = fn (string $session) => [W::feedback($session, 'chest'), W::feedback($session, 'back')];
    $snapshot = W::snapshot(
        today: '2026-10-12',
        sessions: [
            W::session('w1', 1, 1, '2026-09-14 17:30:00', '2026-09-14 18:30:00'),
            W::session('w2', 2, 1, '2026-09-21 17:30:00', '2026-09-21 18:30:00'),
            W::session('w3', 3, 1, '2026-09-28 17:30:00', '2026-09-28 18:30:00'),
        ],
        feedback: [...$flat('w2'), ...$flat('w3')],
    );

    expect(train($snapshot)['current']['next_mesocycle']['active_rest'])->toBe([
        'text' => 'Training has gone flat. An active rest, the deload week plus a week off, is recommended.',
        'tone' => 'info',
    ]);
});

it('MESO-064 a week without feedback breaks the run of flat weeks', function () {
    $snapshot = W::snapshot(
        today: '2026-10-12',
        sessions: [
            W::session('w1', 1, 1, '2026-09-14 17:30:00'),
            W::session('w3', 3, 1, '2026-09-28 17:30:00'),
        ],
        feedback: [W::feedback('w1', 'chest'), W::feedback('w3', 'chest')],
    );

    expect(train($snapshot)['current']['next_mesocycle']['active_rest'])->toBeNull();
});

it('MESO-064 an active rest taken shows as planned and starts the next mesocycle a week later', function () {
    $next = train(W::snapshot(today: '2026-10-12', mesocycles: [W::mesocycle(['active_rest' => true])]))['current']['next_mesocycle'];

    expect($next['start_date'])->toBe('2026-10-26')
        ->and($next['starts_text'])->toBe('Starts Monday 26 Oct')
        ->and($next['active_rest']['tone'])->toBe('good');
});

it('NAV-002 Train offers today\'s session of the running mesocycle', function () {
    // Thursday 8 October, week 4: Day 2 "Lower A".
    expect(train(W::snapshot(today: '2026-10-08'))['todays_session'])->toBe([
        'title' => 'Week 4 · Day 2 · Lower A',
        'mesocycle_id' => W::MESO,
        'week' => 4,
        'day' => 2,
    ]);
});

it('NAV-002 a session already done is still offered so it can be reopened', function () {
    // Monday 5 October: week 4 day 1 is logged and finished.
    expect(train(W::snapshot(today: '2026-10-05'))['todays_session']['title'])->toBe('Week 4 · Day 1 · Upper A');
});

it('NAV-002 a rest day offers no session, nor does a day outside the mesocycle', function () {
    expect(train(W::snapshot(today: '2026-10-06'))['todays_session'])->toBeNull()
        ->and(train(W::snapshot(today: '2026-10-26'))['todays_session'])->toBeNull()
        ->and(train(W::snapshot(today: '2026-10-08', mesocycles: [W::mesocycle(['ended_on' => '2026-10-07'])]))['todays_session'])->toBeNull();
});

it('NAV-002 MESO-005 a floating split\'s days fall on the training weekdays in order', function () {
    $floating = W::mesocycle(['days' => array_map(fn (array $day) => array_replace($day, ['weekday' => null]), W::days())]);

    // The user trains Tuesday and Friday: Day 2 falls on Friday 9 October.
    expect(train(W::snapshot(today: '2026-10-09', mesocycles: [$floating], trainingDays: [5, 2]))['todays_session']['title'])
        ->toBe('Week 4 · Day 2 · Lower A');
});

it('NAV-002 without a plan the weeks run from the mesocycle\'s start', function () {
    expect(train(W::snapshot(today: '2026-10-08', withPlan: false))['todays_session']['week'])->toBe(4);
});
