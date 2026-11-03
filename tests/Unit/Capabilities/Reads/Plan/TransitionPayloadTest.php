<?php

use App\Capabilities\Reads\Plan\PlanPayload;
use App\Support\Plan\PhaseIntake;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// rp-clone's PhaseTransitionModelTests (the read side). The cut runs 31 August to 19 October, 7 weeks, and is the
// last phase; today, 14 October, is in its final week, so its transition is due. Its trend falls from 90 kg.

/** @param  array<string, mixed>  $world */
function transition(?PhaseIntake $intake = null, array $world = [], string $type = 'fatLoss'): ?array
{
    $snapshot = W::snapshot(...[
        'phases' => [W::phase(W::CUT, $type, '2026-08-31', '2026-10-19', $type === 'muscleGain' ? 0.375 : -0.75)],
        'profile' => W::NUTRITION,
        ...$world,
    ]);

    return PlanPayload::build($snapshot, $intake ?? new PhaseIntake(0.87, 2100, 60, 200))->toArray()['transition'];
}

/** @return array<string, mixed> */
function transitionOption(array $transition, string $choice, bool $gainFirst = false): array
{
    return collect($transition['options'])->first(fn (array $o) => $o['choice'] === $choice && $o['gain_first'] === $gainFirst);
}

it('PH-030 no transition is due while a phase follows the ending one, or before the last phase\'s final week', function () {
    $followed = transition(world: ['phases' => [
        W::phase(W::CUT, 'fatLoss', '2026-08-31', '2026-10-19', -0.75),
        W::phase(W::HOLD, 'maintenance', '2026-10-19', '2026-11-16', 0),
    ]]);

    expect($followed)->toBeNull()
        ->and(PlanPayload::build(W::snapshot())->toArray()['transition'])->toBeNull()
        ->and(transition(world: ['today' => '2026-10-11']))->toBeNull()
        ->and(transition(world: ['today' => '2026-10-12']))->not->toBeNull();
});

it('PH-030 PH-007 the last phase in its final week, or over, is due: its summary in the user\'s units', function () {
    $due = transition();

    expect($due)->toMatchArray([
        'phase_id' => W::CUT, 'title' => 'End Fat loss', 'length_text' => '7 weeks', 'weight_change_text' => '-3.5 kg',
        'rate_text' => '-0.56% a week', 'adherence_text' => '87%', 'mesocycles_text' => '1 mesocycle',
        'next_from_text' => 'What comes next, from 19 Oct',
    ])->and(transition(world: ['today' => '2026-11-04'])['next_from_text'])->toBe('What comes next, from 19 Oct');
});

it('PH-031 PH-032 after Fat loss Maintenance is recommended first, then Gain first, then every other type', function () {
    $options = transition()['options'];

    expect(array_map(fn ($o) => [$o['choice'], $o['gain_first'], $o['is_recommended'], $o['is_selected']], $options))->toBe([
        ['maintenance', false, true, true], ['muscleGain', true, true, false], ['fatLoss', false, false, false],
        ['miniCut', false, false, false], ['muscleGain', false, false, false],
    ])->and($options[1]['title'])->toBe('Gain first');
});

it('PH-031 Maintenance after a cut runs 2/3 to 2x its length, defaulting to whole mesocycles', function () {
    $lengths = transitionOption(transition(), 'maintenance')['lengths'];

    expect(array_column($lengths, 'weeks'))->toBe(range(5, 14))
        ->and(collect($lengths)->firstWhere('is_selected')['weeks'])->toBe(5)
        ->and($lengths[0]['ends_text'])->toBe('Ends 22 Nov');
});

it('PH-033 after Muscle gain Maintenance runs 4 to 8 weeks and a Mini-cut is the other recommendation', function () {
    $due = transition(type: 'muscleGain');

    expect(array_column(transitionOption($due, 'maintenance')['lengths'], 'weeks'))->toBe(range(4, 8))
        ->and(transitionOption($due, 'miniCut')['is_recommended'])->toBeTrue()
        ->and(transitionOption($due, 'maintenance')['detail'])->toBe('4 to 8 weeks to consolidate the gain.');
});

it('PH-032 Gain first has no lengths to pick and says how its three phases are saved', function () {
    $gainFirst = transitionOption(transition(), 'muscleGain', gainFirst: true);

    expect($gainFirst['lengths'])->toBe([])
        ->and($gainFirst['note'])->toStartWith('A Muscle gain needs an end date here')
        ->and($gainFirst['refusal'])->toBeNull();
});

it('PH-034 a cut straight after a cut warns on every length', function () {
    $lengths = transitionOption(transition(), 'fatLoss')['lengths'];

    expect(collect($lengths)->every(fn ($l) => $l['warnings'][0]['text'] === 'Fat loss right after Fat loss is not recommended. Run a Maintenance phase between them.'))->toBeTrue();
});

it('PH-038 a Mini-cut over half the Muscle gain before it warns', function () {
    $lengths = collect(transitionOption(transition(type: 'muscleGain'), 'miniCut')['lengths']);

    expect($lengths->firstWhere('weeks', 3)['warnings'])->toBe([])
        ->and($lengths->firstWhere('weeks', 4)['warnings'][0]['text'])->toContain('mini-cut');
});

it('PH-035 starting calories come from the formula while no measured expenditure is read, and none in training-only mode', function () {
    expect(transitionOption(transition(), 'maintenance')['starting_calories_text'])
        ->toBe('Starts from the formula estimate of 2,496 kcal a day. There is no measured estimate to use yet.')
        ->and(transitionOption(transition(world: ['trainingOnly' => true]), 'maintenance')['starting_calories_text'])->toBeNull();
});

it('PH-036 Maintenance after a cut defaults to a resensitization mesocycle, with balanced as the other choice', function () {
    $templates = transitionOption(transition(), 'maintenance')['templates'];
    $gain = transitionOption(transition(), 'muscleGain')['templates'];

    expect(array_map(fn ($t) => [$t['template'], $t['is_selected']], $templates))->toBe([['resensitization', true], ['balanced', false]])
        ->and($templates[0]['detail'])->toStartWith('A resensitization mesocycle: every muscle at Maintain, 2 to 3 full-body sessions a week')
        ->and($templates[0]['training_lines'][0]['text'])->toBe('All muscles Maintain')
        ->and($templates[1]['training_lines'][0]['text'])->toBe('All muscles Grow')
        ->and(array_column($gain, 'template'))->toBe(['standard'])
        ->and(array_column(transitionOption(transition(type: 'muscleGain'), 'maintenance')['templates'], 'template'))->toBe(['standard']);
});

it('PH-037 Maintenance after a cut holds the new lower weight', function () {
    expect(transitionOption(transition(), 'maintenance')['corridor_text'])
        ->toBe('Hold your new weight of about 86.5 kg, not the weight you started the diet at. Your trend stays between 85.6 kg and 87.4 kg.');
});

it('PH-044 Maintenance after a cut steps calories up from the last cut target every 3 weeks, up to maintenance', function () {
    $stepUp = transitionOption(transition(), 'maintenance')['step_up'];

    expect(array_column(array_column($stepUp['steps'], 'step'), 'value'))->toBe([250.0, 500.0])
        ->and($stepUp['explanation'])->toContain('A 12-week diet is a 16-week process.')
        ->and(array_column(array_slice($stepUp['steps'][0]['schedule'], 0, 4), 'kcal_text'))->toBe(['2,350 kcal', '2,350 kcal', '2,350 kcal', '2,496 kcal'])
        ->and($stepUp['steps'][0]['schedule'])->toHaveCount(14)
        ->and(transitionOption(transition(), 'muscleGain')['step_up'])->toBeNull()
        ->and(transitionOption(transition(type: 'muscleGain'), 'maintenance')['step_up'])->toBeNull()
        ->and(transitionOption(transition(world: ['trainingOnly' => true]), 'maintenance')['step_up'])->toBeNull();
});

it('PH-046 a new Fat loss or Muscle gain asks the readiness questions; Maintenance asks none', function () {
    $due = transition();

    expect(array_column(transitionOption($due, 'fatLoss')['readiness'], 'text'))->toBe(['Is your diet fatigue low?', 'Is your training fatigue low?', 'Is your calendar clear for the next phase?'])
        ->and(transitionOption($due, 'maintenance')['readiness'])->toBe([]);
});

it('PH-046 fat or training-day carbs below the readiness floor warn before a new Fat loss or Muscle gain', function () {
    $low = transition(new PhaseIntake(0.9, 2100, 40, 200));
    $fine = transition(new PhaseIntake(0.9, 2100, 80, 400));

    expect(array_column(transitionOption($low, 'muscleGain')['warnings'], 'text'))->toBe([
        'Fat is under 0.3 g per lb of lean mass', 'Training-day carbs are under 1.5 g per lb of lean mass',
    ])->and(transitionOption($fine, 'muscleGain')['warnings'])->toBe([])
        ->and(transitionOption($low, 'maintenance')['warnings'])->toBe([]);
});

it('PH-040 a Muscle gain that has put on too much fat warns', function () {
    $due = transition(type: 'muscleGain', world: ['weighIns' => W::weighIns(startKg: 90, perDayKg: 0.25)]);

    expect(transitionOption($due, 'maintenance')['warnings'][0]['text'])->toContain('body fat');
});

it('PLAN-007 a training-only transition has no weight, rate or adherence', function () {
    $due = transition(world: ['trainingOnly' => true]);

    expect([$due['weight_change_text'], $due['rate_text'], $due['adherence_text']])->toBe([null, null, null])
        ->and($due['length_text'])->toBe('7 weeks');
});

it('PH-031 two cuts in the past 12 months make Maintenance default to the cut\'s full length', function () {
    $world = ['planStart' => '2026-06-01', 'mesocycles' => []];
    $once = transition(world: [...$world, 'phases' => [W::phase(W::CUT, 'fatLoss', '2026-07-06', '2026-10-19', -0.75)]]);
    $twice = transition(world: [...$world, 'phases' => [
        W::phase(W::GAIN, 'fatLoss', '2026-06-01', '2026-06-29', -0.75),
        W::phase(W::HOLD, 'maintenance', '2026-06-29', '2026-07-06', 0),
        W::phase(W::CUT, 'fatLoss', '2026-07-06', '2026-10-19', -0.75),
    ]]);

    expect(collect(transitionOption($once, 'maintenance')['lengths'])->firstWhere('is_selected')['weeks'])->toBe(10)
        ->and(collect(transitionOption($twice, 'maintenance')['lengths'])->firstWhere('is_selected')['weeks'])->toBe(15);
});

it('PH-039 a Mini-cut next trains every muscle at Emphasize with an MEV +2 ceiling', function () {
    $lines = transitionOption(transition(type: 'muscleGain'), 'miniCut')['templates'][0]['training_lines'];

    expect(array_column(array_slice($lines, 0, 3), 'text'))->toBe(['All muscles Emphasize', 'Volume starts at MEV sets', 'Volume ceiling: MEV +2 sets']);
});

it('PH-031 PLAN-007 a training-only Maintenance after a long cut opens on its shortest offered length', function () {
    $due = transition(world: ['trainingOnly' => true, 'planStart' => '2026-07-20', 'phases' => [W::phase(W::CUT, 'fatLoss', '2026-07-20', '2026-10-19', -0.75)]]);
    $lengths = transitionOption($due, 'maintenance')['lengths'];

    expect($lengths[0]['weeks'])->toBe(9)
        ->and(collect($lengths)->firstWhere('is_selected')['weeks'])->toBe(9);
});

it('SAFE-001 neither Add a phase nor the prebuilt plans offer a cut while the user is trying to conceive', function () {
    $payload = PlanPayload::build(W::snapshot(profile: ['screening' => ['tryingToConceive']]))->toArray();
    $trainingOnly = PlanPayload::build(W::snapshot(trainingOnly: true, profile: ['screening' => ['tryingToConceive']]))->toArray();

    expect(array_column($payload['add_phase']['types'], 'type'))->toBe(['muscleGain', 'maintenance'])
        ->and($payload['prebuilt_plans'])->toBe([])
        ->and(array_column($trainingOnly['add_phase']['types'], 'type'))->toHaveCount(4);
});
