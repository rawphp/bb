<?php

use App\Domain\Model\HeightUnit;
use App\Domain\Progress\ProgressScope;
use App\Models\WeighIn;
use Tests\Unit\Capabilities\Reads\Progress\ProgressWorld as W;

// The chart, summary, share and empty states of the Progress tab (rp-clone ProgressModelTests), from in-memory rows.

it('ANA-001 offers the current phase and the whole plan, selecting the one asked for', function () {
    $phase = W::payload(W::snapshot());
    $plan = W::payload(W::snapshot(), ProgressScope::WholePlan);

    expect($phase['title'])->toBe('Progress')
        ->and($phase['scopes'])->toBe([
            ['value' => 'phase', 'title' => 'Phase', 'selected' => true],
            ['value' => 'plan', 'title' => 'Whole plan', 'selected' => false],
        ])
        ->and(array_column($plan['scopes'], 'selected'))->toBe([false, true]);
});

it('ANA-001 draws the current phase from its start, then the whole plan from the first weigh-in', function () {
    $phase = W::payload(W::snapshot())['chart'];
    $plan = W::payload(W::snapshot(), ProgressScope::WholePlan)['chart'];

    expect($phase['unit'])->toBe('kg')
        ->and($phase['trend'][0]['date'])->toBe('2026-09-01')
        ->and($phase['goal'][0]['date'])->toBe('2026-09-01')
        ->and($plan['trend'][0]['date'])->toBe('2026-09-01')
        // Weigh-ins start on 1 September, so the maintenance phase before it has no trend to range from.
        ->and($plan['trend'])->toHaveCount(40)
        ->and($plan['weights'])->toHaveCount(40)
        ->and(array_column($plan['trend'], 'date')[39])->toBe('2026-10-10');
});

it('ANA-001 draws each phase\'s range in the whole plan scope', function () {
    $snapshot = W::snapshot(weighIns: W::weighIns('2026-08-20', 52));

    $phase = W::payload($snapshot)['chart'];
    $plan = W::payload($snapshot, ProgressScope::WholePlan)['chart'];

    expect($phase['goal'][0]['date'])->toBe('2026-09-01')
        ->and($plan['goal'][0]['date'])->toBe('2026-08-20')
        // Maintenance holds the line at 80 kg within 1% either side.
        ->and($plan['goal'][0])->toBe(['date' => '2026-08-20', 'low' => 79.2, 'goal' => 80.0, 'high' => 80.8]);
});

it('ANA-001 rounds the weights to 1 decimal and the trend and goal to 2', function () {
    $chart = W::payload(W::snapshot(weighIns: W::weighIns(kg: 80.123)))['chart'];

    expect($chart['weights'][0])->toBe(['date' => '2026-09-01', 'value' => 80.1])
        ->and($chart['trend'][0])->toBe(['date' => '2026-09-01', 'value' => 80.12])
        ->and($chart['goal'][0]['goal'])->toBe(80.12);
});

it('ANA-001 words the chart for a screen reader from its first and last trend weight', function () {
    expect(W::payload(W::snapshot())['chart']['accessibility_label'])->toBe('Weight chart. Trend weight from 80.0 kg to 80.0 kg.');

    $rising = array_map(fn (int $i) => W::weighIn('2026-09-'.sprintf('%02d', $i + 1).' 07:00:00', 80 + $i), range(0, 9));
    $label = W::payload(W::snapshot(weighIns: $rising))['chart']['accessibility_label'];

    expect($label)->toMatch('/^Weight chart\. Trend weight from 80\.0 kg to 8[0-9]\.[0-9] kg\.$/');
});

it('ANA-001 shows pounds in the profile\'s unit', function () {
    $payload = W::payload(W::snapshot(profile: ['weight_unit' => 'lb']));
    $chart = $payload['chart'];

    expect($chart['unit'])->toBe('lb')
        ->and($chart['weights'][0]['value'])->toBe(176.4)
        ->and($chart['trend'][0]['value'])->toBe(176.37)
        ->and($chart['accessibility_label'])->toBe('Weight chart. Trend weight from 176.4 lb to 176.4 lb.')
        ->and($payload['summary'][0]['value'])->toBe('176.4 lb');
});

it('ANA-002 the summary has start, change and goal', function () {
    $summary = W::payload(W::snapshot())['summary'];

    // 8 weeks at -0.5% a week from 80 kg.
    expect($summary)->toBe([
        ['label' => 'Start', 'value' => '80.0 kg', 'tone' => 'neutral'],
        ['label' => 'Change', 'value' => '+0.0 kg', 'tone' => 'neutral'],
        ['label' => 'Goal', 'value' => '76.8 kg', 'tone' => 'neutral'],
    ]);
});

it('ANA-002 signs a loss and shows the goal in pounds', function () {
    $falling = array_map(fn (int $i) => W::weighIn('2026-09-'.sprintf('%02d', $i + 1).' 07:00:00', 80 - $i), range(0, 9));
    $kg = W::payload(W::snapshot(weighIns: $falling))['summary'];
    $lb = W::payload(W::snapshot(profile: ['weight_unit' => 'lb']))['summary'];

    expect($kg[1]['value'])->toMatch('/^-[0-9]\.[0-9] kg$/')
        ->and($lb[2]['value'])->toBe('169.3 lb');
});

it('ANA-002 shows a dash for a goal a phase with no end does not have', function () {
    $open = W::phase(W::CUT, 'fatLoss', '2026-09-01', null, -0.5);

    $summary = W::payload(W::snapshot(phases: [$open]))['summary'];

    expect(array_column($summary, 'value')[2])->toBe('–');
});

it('ANA-010 shares the title and the phase summary', function () {
    $share = W::payload(W::snapshot())['share'];

    expect($share)->toBe(['title' => 'My progress', 'message' => 'Fat loss: 80.0 kg to 80.0 kg (+0.0 kg) over 5 weeks']);
});

it('ANA-010 shares pounds when that is the unit', function () {
    $share = W::payload(W::snapshot(profile: ['weight_unit' => 'lb']))['share'];

    expect($share['message'])->toBe('Fat loss: 176.4 lb to 176.4 lb (+0.0 lb) over 5 weeks');
});

it('ANA-001 a phase that started after the last weigh-in has no chart, and says so', function () {
    // 25 weigh-ins from 5 to 29 August; the fat-loss phase started on 1 September.
    $snapshot = W::snapshot(weighIns: W::weighIns('2026-08-05', 25));

    $phase = W::payload($snapshot);
    $plan = W::payload($snapshot, ProgressScope::WholePlan);

    expect($phase['empty'])->toBeNull()
        ->and($phase['chart'])->toBeNull()
        ->and($phase['summary'])->toBe([])
        ->and($phase['share'])->toBeNull()
        ->and($phase['scope_message'])->toBe('No weigh-ins in this phase yet')
        ->and($plan['chart'])->not->toBeNull()
        ->and($plan['scope_message'])->toBeNull();
});

it('ANA-001 says the plan has no weigh-ins when the whole plan scope has none in range', function () {
    // The plan starts after the only weigh-ins.
    $snapshot = W::snapshot(weighIns: W::weighIns('2026-08-05', 25), planStart: '2026-09-05');

    expect(W::payload($snapshot, ProgressScope::WholePlan)['scope_message'])->toBe('No weigh-ins in this plan yet');
});

it('ONB-032 fewer than four weigh-ins show the empty state instead of chart and summary', function () {
    $payload = W::payload(W::snapshot(weighIns: W::weighIns(days: 3)));

    expect($payload['empty'])->toBe(['message' => 'Log 4 weigh-ins to see your trend', 'action_title' => 'Log a weigh-in'])
        ->and($payload['chart'])->toBeNull()
        ->and($payload['summary'])->toBe([])
        ->and($payload['share'])->toBeNull()
        ->and($payload['scope_message'])->toBeNull();
});

it('ONB-032 WT-012 four weigh-ins show the chart', function () {
    $payload = W::payload(W::snapshot(weighIns: W::weighIns(days: 4)));

    expect($payload['empty'])->toBeNull()
        ->and($payload['chart'])->not->toBeNull()
        ->and($payload['summary'])->not->toBe([]);
});

it('ONB-032 no weigh-ins at all is the empty state too', function () {
    $payload = W::payload(W::snapshot(weighIns: []));

    expect($payload['empty']['message'])->toBe('Log 4 weigh-ins to see your trend')
        ->and($payload['chart'])->toBeNull();
});

it('ONB-032 the other cards still show under four weigh-ins', function () {
    $payload = W::payload(W::snapshot(weighIns: W::weighIns(days: 3), targetDays: ['2026-10-10']));

    expect($payload['this_week'])->not->toBeNull()
        ->and(array_column($payload['phase_history'], 'id'))->toBe([W::HOLD])
        ->and($payload['mesocycle_history'])->toHaveCount(2);
});

it('WT-021 draws no waist line until the user has 4 waist readings', function () {
    $three = W::payload(W::snapshot(weighIns: waistReadings([5, 10, 15])))['chart'];

    expect($three['waist'])->toBe([])
        ->and($three['waist_unit'])->toBe('cm');
});

it('WT-021 draws the waist at each day that has a reading, in centimetres', function () {
    $chart = W::payload(W::snapshot(weighIns: waistReadings([0 => 88.0, 14 => 87.5, 28 => 87.5, 39 => 87.0])))['chart'];

    expect($chart['waist_unit'])->toBe('cm')
        ->and($chart['waist'])->toBe([
            ['date' => '2026-09-01', 'value' => 88.0],
            ['date' => '2026-09-15', 'value' => 87.5],
            ['date' => '2026-09-29', 'value' => 87.5],
            ['date' => '2026-10-10', 'value' => 87.0],
        ]);
});

it('WT-021 converts the waist to inches when the height unit is inches', function () {
    $chart = W::payload(W::snapshot(weighIns: waistReadings([0 => 88.0, 14 => 87.5, 28 => 87.5, 39 => 87.0]), profile: ['height_unit' => HeightUnit::FeetInches->value]))['chart'];

    expect($chart['waist_unit'])->toBe('in')
        ->and(array_column($chart['waist'], 'value'))->toBe([34.6, 34.4, 34.4, 34.3]);
});

it('WT-021 counts every waist reading but draws only those in the chart\'s range', function () {
    // Four readings, the first before the current phase began on 1 September.
    $weighIns = W::weighIns('2026-08-20', 52);
    foreach ([0 => 90.0, 20 => 89.0, 30 => 88.5, 40 => 88.0] as $index => $cm) {
        $weighIns[$index]->waist_cm = $cm;
    }

    $chart = W::payload(W::snapshot(weighIns: $weighIns))['chart'];

    expect(array_column($chart['waist'], 'date'))->toBe(['2026-09-09', '2026-09-19', '2026-09-29']);
});

it('WT-021 takes the earliest reading of a day that has a waist', function () {
    $weighIns = W::weighIns();
    $weighIns[0]->waist_cm = 90.0;
    $weighIns[0]->weighed_at = '2026-09-01 07:00:00';
    $weighIns[] = W::weighIn('2026-09-01 06:00:00', 80, 91.0);
    $weighIns[] = W::weighIn('2026-09-01 05:00:00', 80);
    foreach ([10, 20, 30] as $index) {
        $weighIns[$index]->waist_cm = 85.0;
    }

    $waist = W::payload(W::snapshot(weighIns: $weighIns))['chart']['waist'];

    expect($waist[0])->toBe(['date' => '2026-09-01', 'value' => 91.0]);
});

/**
 * 40 days of 80 kg from 1 September with a waist on some of them.
 *
 * @param  array<int, float>|list<int>  $waists  day offset => cm; a list of offsets reads 85 cm on each
 * @return list<WeighIn>
 */
function waistReadings(array $waists): array
{
    $weighIns = W::weighIns();
    foreach ($waists as $offset => $cm) {
        [$index, $value] = array_is_list($waists) ? [$cm, 85.0] : [$offset, $cm];
        $weighIns[$index]->waist_cm = $value;
    }

    return $weighIns;
}
