<?php

use App\Capabilities\Reads\Plan\PlanPayload;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// rp-clone's AddPhaseModelTests and PrebuiltPlanScreenTests (the read side), on PlanWorld: the plan ends with Muscle
// gain on 15 March 2027 (week 28).

function newPhases(...$arguments): array
{
    return PlanPayload::build(W::snapshot(...$arguments))->toArray();
}

/** @return array<string, mixed> the add_phase type */
function addType(array $payload, string $type): array
{
    return collect($payload['add_phase']['types'])->firstWhere('type', $type);
}

it('PLAN-006 PH-001 PH-002 Add a phase offers the four types, from where the plan ends', function () {
    $types = newPhases()['add_phase']['types'];

    expect(array_column($types, 'type'))->toBe(['fatLoss', 'muscleGain', 'maintenance', 'miniCut'])
        ->and(array_column($types, 'title'))->toBe(['Fat loss', 'Muscle gain', 'Maintenance', 'Mini-cut'])
        ->and(addType(newPhases(), 'miniCut')['lengths'][0]['ends_text'])->toBe('Ends 28 Mar');
});

it('PH-033 after Muscle gain the form recommends Maintenance or a Mini-cut', function () {
    $types = newPhases()['add_phase']['types'];

    expect(array_column($types, 'is_recommended', 'type'))->toBe(['fatLoss' => false, 'muscleGain' => false, 'maintenance' => true, 'miniCut' => true]);
});

it('PH-034 after Maintenance it recommends Fat loss or Muscle gain, and an empty plan does too', function () {
    $hold = newPhases(phases: [W::phase(W::HOLD, 'maintenance', '2026-08-31', '2026-11-23', 0)])['add_phase']['types'];
    $empty = newPhases(phases: [], mesocycles: [])['add_phase'];

    expect(array_column($hold, 'is_recommended', 'type'))->toBe(['fatLoss' => true, 'muscleGain' => true, 'maintenance' => false, 'miniCut' => false])
        ->and(array_column($empty['types'], 'is_recommended', 'type'))->toBe(['fatLoss' => true, 'muscleGain' => true, 'maintenance' => false, 'miniCut' => false])
        ->and($empty['starts_text'])->toBe('Starts 12 Oct');
});

it('PLAN-006 nothing can be added after a phase with no end, so the form offers no types', function () {
    $add = newPhases(phases: [W::phase(W::CUT, 'maintenance', '2026-08-31', null, 0)])['add_phase'];

    expect($add['refusal'])->toBe('A phase with no end cannot have phases after it.')
        ->and($add['types'])->toBe([]);
});

it('PH-003 PH-005 lengths follow TBL-010: only Maintenance may have no end', function () {
    $payload = newPhases();

    // PLAN-009, PLAN-025: a Mini-cut's 6-week cap counts deficit weeks, so its diet-break deload lets it run 7.
    expect(array_column(addType($payload, 'miniCut')['lengths'], 'weeks'))->toBe([2, 3, 4, 5, 6, 7])
        ->and(array_column(addType($payload, 'muscleGain')['lengths'], 'weeks'))->toBe(range(4, 16))
        ->and(array_column(addType($payload, 'maintenance')['lengths'], 'weeks'))->toBe([...range(2, 52), null])
        ->and(addType($payload, 'maintenance')['lengths'][51])->toMatchArray(['label' => 'No end', 'ends_text' => 'No end date']);
});

it('PLAN-025 a cut may run past 16 weeks by its diet-break weeks', function () {
    $weeks = array_column(addType(newPhases(), 'fatLoss')['lengths'], 'weeks');

    expect($weeks[0])->toBe(2)
        ->and(max($weeks))->toBeGreaterThan(16)
        ->and(addType(newPhases(trainingOnly: true), 'fatLoss')['lengths'])->toHaveCount(15);
});

it('PH-004 the rates are TBL-010\'s presets, Maintenance holds within ±0.25% and Muscle gain follows experience', function () {
    $payload = newPhases();

    expect(array_column(addType($payload, 'fatLoss')['rates'], 'label'))->toBe(['Highest success', 'Slow & steady'])
        ->and(addType($payload, 'fatLoss')['rates'][0]['rate'])->toBe(['value' => -0.75, 'unit' => '%/wk', 'text' => 'Lose 0.75% a week'])
        ->and(addType($payload, 'fatLoss')['custom_rate_min']['value'])->toBe(-1.0)
        ->and(addType($payload, 'muscleGain')['rates'][0])->toMatchArray(['label' => 'Recommended', 'is_selected' => true])
        ->and(addType($payload, 'muscleGain')['rates'][0]['rate']['value'])->toBe(0.375)
        ->and(addType($payload, 'maintenance')['rates'][0]['rate']['text'])->toBe('Hold weight')
        ->and([addType($payload, 'maintenance')['custom_rate_min']['value'], addType($payload, 'maintenance')['custom_rate_max']['value']])->toBe([-0.25, 0.25])
        ->and(addType($payload, 'miniCut')['custom_rate_min'])->toBeNull();
});

it('PLAN-007 a training-only form has no rates, and each type opens one mesocycle long', function () {
    $type = addType(newPhases(trainingOnly: true), 'fatLoss');

    expect($type['rates'])->toBe([])
        ->and($type['custom_rate_min'])->toBeNull()
        ->and($type['custom_rate_max'])->toBeNull()
        ->and(collect($type['lengths'])->firstWhere('is_selected')['weeks'])->toBe(5);
});

it('PLAN-020 PLAN-026 each length lists the mesocycles that fill it, a cut\'s deloads 4 to 6 weeks apart', function () {
    $twelve = collect(addType(newPhases(), 'fatLoss')['lengths'])->firstWhere('weeks', 12);

    expect($twelve['mesocycles'])->toBe([
        ['label' => 'Mesocycle 1', 'weeks_text' => '6 weeks', 'dates_text' => '15 Mar – 25 Apr'],
        ['label' => 'Mesocycle 2', 'weeks_text' => '6 weeks', 'dates_text' => '26 Apr – 6 Jun'],
    ])->and($twelve['ends_text'])->toBe('Ends 6 Jun');
});

it('PH-034 a cut straight after a cut warns, and so does one after a Maintenance under 60% of the cut before it', function () {
    $afterCut = addType(newPhases(phases: [W::phase(W::CUT, 'fatLoss', '2026-08-31', '2026-11-23', -0.75)]), 'fatLoss');
    $afterShortHold = addType(newPhases(phases: array_slice(W::phases(), 0, 2)), 'fatLoss');

    expect(collect($afterCut['lengths'])->firstWhere('weeks', 8)['warnings'][0]['text'])
        ->toBe('Fat loss right after Fat loss is not recommended. Run a Maintenance phase between them.')
        ->and(collect($afterShortHold['lengths'])->firstWhere('weeks', 8)['warnings'][0]['tone'])->toBe('warning')
        ->and(collect($afterShortHold['lengths'])->firstWhere('weeks', 8)['warnings'])->toHaveCount(1);
});

it('PH-038 a Mini-cut over half the Muscle gain before it warns', function () {
    $lengths = collect(addType(newPhases(phases: [W::phase(W::GAIN, 'muscleGain', '2026-08-31', '2026-10-26', 0.375)]), 'miniCut')['lengths']);

    expect($lengths->firstWhere('weeks', 4)['warnings'])->toBe([])
        ->and($lengths->firstWhere('weeks', 5)['warnings'])->toHaveCount(1);
});

it('TBL-010 a short cut suggests a Mini-cut, a long one warns, and one losing over 10% warns', function () {
    $lengths = collect(addType(newPhases(), 'fatLoss')['lengths']);

    expect(array_column($lengths->firstWhere('weeks', 4)['warnings'], 'text'))->toBe(['Under 6 weeks, a Mini-cut is a better fit than Fat loss.'])
        ->and(array_column($lengths->firstWhere('weeks', 8)['warnings'], 'text'))->toBe([])
        // PLAN-025: 16 weeks hold 3 diet breaks, so 13 weeks at 0.75% stay under 10%; 17 weeks count 14.
        ->and(array_column($lengths->firstWhere('weeks', 16)['warnings'], 'text'))->toBe(['Fat loss over 12 weeks is a long diet. Consider a shorter phase.'])
        ->and(array_column($lengths->firstWhere('weeks', 17)['warnings'], 'text'))->toBe([
            'Fat loss over 12 weeks is a long diet. Consider a shorter phase.',
            'This phase plans to lose more than 10% of your starting bodyweight.',
        ]);
});

it('PH-008 PH-036 PH-039 each type lists its training defaults; Maintenance after a cut is low volume', function () {
    $afterGain = newPhases();
    $afterCut = newPhases(phases: [W::phase(W::CUT, 'fatLoss', '2026-08-31', '2026-11-23', -0.75)]);

    expect(addType($afterGain, 'miniCut')['training_lines'][2]['text'])->toBe('Volume ceiling: MEV +2 sets')
        ->and(addType($afterGain, 'maintenance')['training_lines'][0]['text'])->toBe('All muscles Grow')
        ->and(addType($afterCut, 'maintenance')['training_lines'][0]['text'])->toBe('All muscles Maintain');
});

it('PLAN-030 both prebuilt plans are laid out from where the plan ends, mesocycles and active rest in order', function () {
    $prebuilt = newPhases()['prebuilt_plans'];

    expect(array_column($prebuilt, 'plan'))->toBe(['muscleGain', 'fatLoss'])
        ->and($prebuilt[0])->toMatchArray(['title' => 'Muscle gain plan', 'summary' => "24 weeks from RP's 6-month guide", 'starts_text' => 'Starts 15 Mar', 'refusal' => null])
        ->and(array_column($prebuilt[0]['phases'], 'weeks_text'))->toBe(['9 weeks', '7 weeks', '2 weeks', '6 weeks'])
        ->and(array_column($prebuilt[0]['phases'], 'rate_text'))->toBe(['Gain 0.5% a week', 'Gain 0.375% a week', 'Hold weight', 'Lose 0.75% a week'])
        ->and($prebuilt[0]['phases'][0]['dates_text'])->toBe('15 Mar – 16 May')
        ->and(array_map(fn ($s) => [$s['kind'], $s['start_week'], $s['weeks']], $prebuilt[1]['segments']))->toBe([
            ['mesocycle', 0, 5], ['mesocycle', 5, 5], ['mesocycle', 10, 5], ['active_rest', 14, 2], ['mesocycle', 16, 4], ['mesocycle', 20, 4],
        ]);
});

it('PLAN-030 PLAN-007 a training-only prebuilt plan has no rates', function () {
    expect(array_unique(array_column(newPhases(trainingOnly: true)['prebuilt_plans'][1]['phases'], 'rate_text')))->toBe([null]);
});

it('PLAN-030 PLAN-006 there are no prebuilt plans after a phase with no end, or without a plan', function () {
    expect(newPhases(phases: [W::phase(W::CUT, 'maintenance', '2026-08-31', null, 0)])['prebuilt_plans'])->toBe([])
        ->and(PlanPayload::build(null)->toArray()['prebuilt_plans'])->toBe([])
        ->and(PlanPayload::build(null)->toArray()['add_phase']['types'])->toBe([]);
});

it('PLAN-020 a phase with no end gets one mesocycle at a time, as long as the user\'s experience sets', function () {
    $noEnd = fn (string $experience) => collect(addType(newPhases(profile: ['experience' => $experience]), 'maintenance')['lengths'])->firstWhere('weeks', null)['mesocycles'];

    expect($noEnd('intermediate'))->toBe([['label' => 'Mesocycle 1', 'weeks_text' => '5 weeks', 'dates_text' => '15 Mar – 18 Apr']])
        ->and($noEnd('beginner')[0]['weeks_text'])->toBe('6 weeks');
});
