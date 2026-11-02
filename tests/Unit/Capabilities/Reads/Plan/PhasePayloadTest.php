<?php

use App\Capabilities\Reads\Plan\PhasePayload;
use App\Domain\Model\PhaseType;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// rp-clone's PhaseDetailModelTests (the read side), on PlanWorld.

function phasePayload(string $id = W::CUT, ...$arguments): array
{
    $plan = W::snapshot(...$arguments);

    return PhasePayload::build($plan, $plan->findPhase($id))->toArray();
}

/** @return array<int|string, array<string, mixed>> */
function lengthsByWeeks(array $phase): array
{
    $lengths = [];
    foreach ($phase['lengths'] as $length) {
        $lengths[$length['weeks'] ?? 'none'] = $length;
    }

    return $lengths;
}

function cutWith(?string $event = null, string $type = 'fatLoss'): array
{
    return [W::phase(W::CUT, $type, '2026-08-31', '2026-11-23', -0.75, event: $event), ...array_slice(W::phases(), 1)];
}

it('PLAN-004 a phase opens with its type, dates, rate, weights and progress', function () {
    $phase = phasePayload();

    expect($phase)->toMatchArray([
        'id' => W::CUT, 'type' => 'fatLoss', 'title' => 'Fat loss', 'dates_text' => '31 Aug – 22 Nov',
        'progress' => 0.5, 'progress_text' => 'Week 7 of 12', 'length_text' => '12 weeks', 'rate_text' => 'Lose 0.75% a week',
        'start_weight_text' => '90.0 kg', 'can_end' => true,
    ])
        ->and($phase['current_weight_text'])->toMatch('/^8\d\.\d kg$/')
        ->and($phase['change_text'])->toStartWith('-')
        // PLAN-025: the two diet-break deloads (weeks 5 and 11) do not count: 90 × (1 − 0.0075 × 10).
        ->and($phase['target_weight_text'])->toBeIn(['83.2 kg', '83.3 kg']);
});

it('PLAN-004 a phase that has not started has no progress or weights yet', function () {
    $phase = phasePayload(W::HOLD);

    expect($phase)->toMatchArray([
        'progress' => 0.0, 'progress_text' => 'Starts in 6 weeks', 'start_weight_text' => null, 'target_weight_text' => null,
        'current_weight_text' => null, 'change_text' => null, 'can_end' => false,
    ]);
});

it('PLAN-004 training-only shows no rate or target weight', function () {
    $phase = phasePayload(trainingOnly: true);

    expect($phase['rate_text'])->toBeNull()
        ->and($phase['target_weight_text'])->toBeNull()
        ->and($phase['start_weight_text'])->toBe('90.0 kg');
});

it('PLAN-004 a Maintenance with no end has no length, progress or target', function () {
    $phase = phasePayload(W::CUT, phases: [W::phase(W::CUT, 'maintenance', '2026-08-31', null, 0)]);

    expect($phase)->toMatchArray(['length_text' => 'No end', 'progress' => null, 'progress_text' => 'Week 7', 'target_weight_text' => null]);
});

it('PH-006 the lengths stay within TBL-010 and keep the weeks already run', function () {
    $lengths = array_column(phasePayload()['lengths'], 'weeks');

    // Fat loss runs 2 to 16 deficit weeks; the two diet breaks do not count (PLAN-025), and week 7 has begun.
    expect($lengths[0])->toBe(7)
        // Three deloads fall inside 19 weeks (5, 11 and 15), so 19 holds 16 deficit weeks.
        ->and(end($lengths))->toBe(19)
        ->and($lengths)->not->toContain(null)
        ->and(lengthsByWeeks(phasePayload())[12]['is_selected'])->toBeTrue();
});

it('PH-006 a Maintenance with nothing after it may lose its end; one with phases after it may not', function () {
    $alone = array_column(phasePayload(W::HOLD, phases: array_slice(W::phases(), 0, 2))['lengths'], 'weeks');
    $before = array_column(phasePayload(W::HOLD)['lengths'], 'weeks');

    expect($alone)->toContain(null)
        ->and($before)->not->toContain(null)
        ->and(lengthsByWeeks(phasePayload(W::HOLD, phases: array_slice(W::phases(), 0, 2)))['none']['ends_text'])->toBe('No end date');
});

it('PLAN-021 a phase ending mid-mesocycle offers to move the end to the deload, change the mesocycle, or keep the end', function () {
    $fixes = lengthsByWeeks(phasePayload())[11]['fixes'];

    expect($fixes)->toBe([
        ['fix' => 'move_end_to_deload', 'text' => 'End the phase after the deload on 22 Nov'],
        ['fix' => 'change_mesocycle_length', 'text' => 'Shorten Meso 2 to 5 weeks so its deload is the last week'],
        ['fix' => 'keep_end', 'text' => 'Keep 15 Nov as the end'],
    ]);
});

it('PLAN-021 ending on a deload offers no fixes, and a fix that does not fit is not offered', function () {
    $lengths = lengthsByWeeks(phasePayload());

    expect($lengths[12]['fixes'])->toBe([])
        ->and(array_column($lengths[8]['fixes'], 'fix'))->toBe(['keep_end']);
});

it('PH-007 only the running phase can be ended', function () {
    expect(phasePayload()['can_end'])->toBeTrue()
        ->and(phasePayload(W::HOLD)['can_end'])->toBeFalse();
});

it('PH-009 a new goal starts next week and runs to the phase end; what usually follows comes first', function () {
    $goals = phasePayload()['goals'];

    expect(array_column($goals, 'type'))->toBe(['maintenance', 'muscleGain', 'miniCut'])
        ->and($goals[0])->toMatchArray([
            'title' => 'Maintenance', 'from_text' => 'From 19 Oct', 'length_text' => '5 weeks', 'rate_text' => 'Hold weight',
            'refusal' => null, 'progression_text' => 'Meso 2 follows Maintenance progression from week 2.',
        ])
        // PH-036: Maintenance after Fat loss takes the resensitization defaults.
        ->and($goals[0]['training_lines'][0]['text'])->toBe('All muscles Maintain');
});

it('PH-002 PH-009 Mini-cut is offered as a goal, and a goal too long for its type is refused with a reason', function () {
    $plan = W::snapshot(today: '2026-09-02');
    $goal = (new PhaseChanges($plan))->goal($plan->findPhase(W::CUT), PhaseType::MiniCut);

    expect($goal->weeks)->toBe(11)
        ->and($goal->refusal)->toBe('A Mini-cut phase can run at most 6 weeks. Diet-break weeks do not count.');
});

it('PH-009 a phase that starts next week or later is changed in place', function () {
    $plan = W::snapshot();
    $goal = (new PhaseChanges($plan))->goal($plan->findPhase(W::HOLD), PhaseType::MuscleGain);

    expect($goal->replacesInPlace)->toBeTrue()
        ->and($goal->startWeek)->toBe(12)
        ->and($goal->weeks)->toBe(4)
        ->and($goal->progressionFromWeek)->toBeNull();
});

it('PH-009 a phase with no end gets one when the new type needs it', function () {
    $plan = W::snapshot(phases: [W::phase(W::CUT, 'maintenance', '2026-08-31', null, 0)]);
    $goal = (new PhaseChanges($plan))->goal($plan->findPhase(W::CUT), PhaseType::FatLoss);

    expect($goal->weeks)->toBe(6)
        ->and($goal->startWeek)->toBe(7);
});

it('PH-010 Muscle gain warns, without blocking, when more than two muscles are emphasized', function () {
    $mesocycles = [W::mesocycle(W::MESO2, 'Meso 2', W::CUT, '2026-10-12', 6, ['chest' => 'emphasize', 'back' => 'emphasize', 'quads' => 'emphasize'])];
    $gain = array_column(phasePayload(mesocycles: $mesocycles)['goals'], null, 'type')['muscleGain'];

    expect(array_column($gain['warnings'], 'text'))->toContain('Specialising more than 2 muscle groups spreads recovery too thin.')
        ->and($gain['refusal'])->toBeNull();
});

it('PH-011 Fat loss warns when some muscles are emphasized and others grow or maintain', function () {
    $mesocycles = [W::mesocycle(W::MESO2, 'Meso 2', W::HOLD, '2026-10-12', 6, ['chest' => 'emphasize', 'quads' => 'grow'])];
    $phases = [W::phase(W::CUT, 'maintenance', '2026-08-31', '2026-11-23', 0), ...array_slice(W::phases(), 1)];
    $goals = array_column(phasePayload(phases: $phases, mesocycles: $mesocycles)['goals'], null, 'type');

    expect(array_column($goals['fatLoss']['warnings'], 'text'))->toBe(['Specialising during a cut works against recovery.'])
        ->and(array_column($goals['miniCut']['warnings'], 'text'))->toBe(['Specialising during a cut works against recovery.']);
});

it('PH-012 Muscle gain warns when the current mesocycle is a low-volume plan; other goals do not', function () {
    $goals = array_column(phasePayload()['goals'], null, 'type');

    expect(array_column($goals['muscleGain']['warnings'], 'text'))->toBe(['A surplus with a low-volume plan wastes the phase.'])
        ->and($goals['maintenance']['warnings'])->toBe([]);
});

it('PH-042 a phase ending after its event warns, and so does a length that would', function () {
    $phase = phasePayload(phases: cutWith('2026-11-15'));

    expect($phase['warnings'])->toBe([['text' => 'This phase ends after your event on 15 Nov.', 'tone' => 'warning']])
        ->and($phase['event_date'])->toBe('2026-11-15')
        ->and($phase['event_text'])->toBe('32 days to your event')
        ->and(lengthsByWeeks($phase)[11]['warnings'])->toBe([])
        ->and(lengthsByWeeks($phase)[13]['warnings'])->toHaveCount(1);
});

it('PLAN-028 offers to move the phase end to the event week, and not once it is there', function () {
    expect(phasePayload(phases: cutWith('2026-11-15'))['event_offer'])->toBe(['text' => 'End the phase in your event week, on 15 Nov?', 'weeks' => 11])
        ->and(phasePayload(phases: cutWith('2026-11-20'))['event_offer'])->toBeNull();
});

it('PLAN-004 a finished phase can no longer change', function () {
    $phase = phasePayload(today: '2026-11-30');

    expect($phase['progress_text'])->toBe('Finished')
        ->and($phase['lengths'])->toBe([])
        ->and($phase['goals'])->toBe([])
        ->and($phase['can_end'])->toBeFalse();
});

it('PLAN-001 a phase is found by its id in any letter case', function () {
    expect(W::snapshot()->findPhase(strtolower(W::CUT))?->id)->toBe(W::CUT)
        ->and(W::snapshot()->findPhase('nope'))->toBeNull()
        ->and(PlanSnapshot::OPEN)->toBe(PHP_INT_MAX);
});
