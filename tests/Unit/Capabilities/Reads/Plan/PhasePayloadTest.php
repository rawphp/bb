<?php

use App\Capabilities\Reads\Plan\PhasePayload;
use App\Capabilities\Reads\Plan\PlanPayload;
use App\Domain\Model\PhaseType;
use App\Models\PlanWeek;
use App\Models\WeighIn;
use App\Support\Plan\PhaseChanges;
use App\Support\Plan\PlanSnapshot;
use Illuminate\Support\Collection;
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

    // Fat loss runs 2 to 16 deficit weeks; the two diet breaks do not count (PLAN-025). Week 7 has begun, and
    // ending it there is End phase (PH-007), so the shortest is 8.
    expect($lengths[0])->toBe(8)
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
        ->and(W::snapshot()->findPhase('nope'))->toBeNull();
});

it('PLAN-025 a deload week with its diet break off counts towards the sixteen-week cap and the weekly rate', function () {
    $cut = [W::phase(W::CUT, 'fatLoss', '2026-08-31', '2026-11-23', -0.75)];
    $mesocycles = array_slice(W::mesocycles(), 0, 2);
    $on = phasePayload(phases: $cut, mesocycles: $mesocycles);
    $off = phasePayload(phases: $cut, mesocycles: $mesocycles, weeks: [W::week('2026-11-16', dietBreakOff: true)]);

    // Weeks 5 and 11 are diet breaks; with week 11 off only one deficit-free week is left.
    expect(max(array_column($on['lengths'], 'weeks')))->toBe(18)
        ->and(max(array_column($off['lengths'], 'weeks')))->toBe(17)
        ->and($on['target_weight_text'])->toBeIn(['83.2 kg', '83.3 kg'])
        // 90 × (1 − 0.0075 × 11).
        ->and($off['target_weight_text'])->toBe('82.6 kg');
});

it('PH-006 PH-007 a length that ends the running phase this week is End phase, not a new length', function () {
    $plan = W::snapshot();
    $changes = new PhaseChanges($plan);

    expect(array_column(phasePayload()['lengths'], 'weeks'))->not->toContain(7)
        ->and($changes->lengthRefusal($plan->findPhase(W::CUT), 7))->toBe('That ends the phase this week. Use End phase, which asks first.')
        ->and($changes->lengthRefusal($plan->findPhase(W::HOLD), 53))->toBe('A phase can run at most 52 weeks.');
});

it('PLAN-021 MESO-042 changing the mesocycle length is offered only where the mesocycle may take that length', function () {
    $lengths = lengthsByWeeks(phasePayload());
    $stored = W::mesocycle(W::MESO2, 'Meso 2', W::CUT, '2026-10-12', 6);
    $stored->later_weeks = [['01JB6Q400A2B3C4D5E6F7G8H9J']];
    $rearranged = lengthsByWeeks(phasePayload(mesocycles: [W::mesocycles()[0], $stored, W::mesocycles()[2]]));

    expect(array_column($lengths[11]['fixes'], 'fix'))->toContain('change_mesocycle_length')
        // SCH-039: weeks rearranged for new training days keep their number.
        ->and(array_column($rearranged[11]['fixes'], 'fix'))->not->toContain('change_mesocycle_length');
});

it('PH-007 PLAN-021 moving the end to a deload is not offered when that ends the running phase this week', function () {
    $cut = [W::phase(W::CUT, 'fatLoss', '2026-08-31', '2026-12-21', -0.75)];

    // Today is in Meso 2's deload (week 11); Meso 3 starts in week 12, so length 13 ends mid-Meso 3.
    $fixes = lengthsByWeeks(phasePayload(today: '2026-11-18', phases: $cut))[13]['fixes'];

    expect(array_column($fixes, 'fix'))->not->toContain('move_end_to_deload');
});

it('PLAN-021 SCH-060 a shortened mesocycle leaves its paused weeks out of its length', function () {
    $paused = (new PlanWeek)->forceFill([
        'user_id' => 1, 'start_date' => '2026-10-26', 'is_deload' => false, 'is_diet_break' => false,
        'diet_break_off' => false, 'is_away' => true, 'away_training' => 'pause',
    ]);

    // Week 8 is paused, so Meso 2 runs weeks 6 to 12 and its deload is week 12, past the cut's last week (11).
    $fixes = lengthsByWeeks(phasePayload(weeks: [$paused]))[12]['fixes'];

    expect($fixes)->toContain(['fix' => 'change_mesocycle_length', 'text' => 'Shorten Meso 2 to 5 weeks so its deload is the last week']);
});

it('PLAN-004 weigh-ins are read only for a phase\'s weights, from 60 days before it starts', function () {
    $asked = [];
    $plan = new PlanSnapshot(
        today: PlanSnapshot::day('2026-10-14'),
        plan: W::snapshot()->plan,
        profile: W::snapshot()->profile,
        phases: new Collection(W::phases()),
        mesocycles: new Collection(W::mesocycles()),
        planWeeks: new Collection,
        weighIns: function (DateTimeImmutable $from) use (&$asked) {
            $asked[] = $from->format('Y-m-d');

            return new Collection(W::weighIns());
        },
    );

    PlanPayload::build($plan);
    (new PhaseChanges($plan))->lengths($plan->findPhase(W::CUT));
    expect($asked)->toBe([]);

    $phase = PhasePayload::build($plan, $plan->findPhase(W::CUT))->toArray();
    expect($asked)->toBe(['2026-07-02'])
        ->and($phase['start_weight_text'])->toBe('90.0 kg');
});

it('SET-012 a snapshot\'s weigh-ins since a day are on the user\'s local days', function (string $zone, array $read) {
    $weighIns = [];
    foreach (['2026-07-01 13:00:00', '2026-07-01 21:00:00', '2026-07-02 06:00:00'] as $index => $at) {
        $weighIns[] = (new WeighIn)->forceFill(['id' => $index + 1, 'user_id' => 1, 'weight_kg' => 90.0, 'weighed_at' => $at]);
    }
    $plan = new PlanSnapshot(
        today: PlanSnapshot::day('2026-10-14'),
        plan: W::snapshot()->plan,
        profile: W::snapshot()->profile,
        phases: new Collection(W::phases()),
        mesocycles: new Collection(W::mesocycles()),
        planWeeks: new Collection,
        weighIns: new Collection($weighIns),
        zone: new DateTimeZone($zone),
    );

    $since = $plan->weighInsSince(PlanSnapshot::day('2026-07-02'));

    expect($since->map(fn (WeighIn $w) => $w->weighed_at->format('Y-m-d H:i:s'))->all())->toBe($read);
})->with([
    'Brisbane' => ['Australia/Brisbane', ['2026-07-01 21:00:00', '2026-07-02 06:00:00']],
    'UTC' => ['UTC', ['2026-07-02 06:00:00']],
]);
