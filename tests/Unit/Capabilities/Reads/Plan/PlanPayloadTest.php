<?php

use App\Capabilities\Reads\Plan\PlanPayload;
use Tests\Unit\Capabilities\Reads\Plan\PlanWorld as W;

// rp-clone's PlanTimelineModelTests and DietBreakToggleTests (the read side), on PlanWorld.

function planPayload(...$arguments): array
{
    return PlanPayload::build(W::snapshot(...$arguments))->toArray();
}

it('PLAN-001 the plan is an ordered list of phases with type, dates and rate', function () {
    $plan = planPayload();

    expect(array_column($plan['phases'], 'type'))->toBe(['fatLoss', 'maintenance', 'muscleGain'])
        ->and(array_column($plan['phases'], 'start_week'))->toBe([0, 12, 16])
        ->and(array_column($plan['phases'], 'weeks'))->toBe([12, 4, 12])
        ->and(array_column($plan['phases'], 'rate_text'))->toBe(['Lose 0.75% a week', 'Hold weight', 'Gain 0.375% a week'])
        ->and(array_column($plan['phases'], 'title'))->toBe(['Fat loss', 'Maintenance', 'Muscle gain'])
        ->and(array_column($plan['phases'], 'dates_text'))->toBe(['31 Aug – 22 Nov', '23 Nov – 20 Dec', '21 Dec – 14 Mar']);
});

it('PLAN-001 a user without a plan has an empty timeline', function () {
    $plan = PlanPayload::build(null)->toArray();

    expect($plan['phases'])->toBe([])
        ->and($plan['mesocycles'])->toBe([])
        ->and($plan['current'])->toBeNull()
        ->and($plan['add_phase']['refusal'])->not->toBeNull();
});

it('PLAN-002 mesocycles sit under the phases on the same week grid', function () {
    $plan = planPayload();

    expect(array_column($plan['mesocycles'], 'id'))->toBe([W::MESO1, W::MESO2, W::MESO3])
        ->and(array_column($plan['mesocycles'], 'start_week'))->toBe([0, 6, 12])
        ->and(array_column($plan['mesocycles'], 'weeks'))->toBe([6, 6, 4]);
});

it('PLAN-002 a mesocycle running past its phase keeps its weeks, and its deload follows the phase covering that week', function () {
    $plan = planPayload(mesocycles: [
        W::mesocycle(W::MESO1, 'Meso 1', W::CUT, '2026-08-31', 6),
        W::mesocycle(W::MESO2, 'Meso 2', W::CUT, '2026-10-12', 8),
    ]);

    expect($plan['mesocycles'][1]['weeks'])->toBe(8)
        ->and($plan['deloads'][1]['week'])->toBe(13)
        ->and($plan['deloads'][1]['diet_break_action'])->toBe('none');
});

it('PLAN-003 the timeline shows the deload weeks, the current mesocycle and today part way through its week', function () {
    $plan = planPayload();

    expect(array_column($plan['deloads'], 'week'))->toBe([5, 11, 15])
        ->and(array_column($plan['deloads'], 'dates_text'))->toBe(['5 – 11 Oct', '16 – 22 Nov', '14 – 20 Dec'])
        ->and(array_column($plan['deloads'], 'start_date'))->toBe(['2026-10-05', '2026-11-16', '2026-12-14'])
        ->and(array_column($plan['mesocycles'], 'is_current'))->toBe([false, true, false])
        ->and($plan['today_position'])->toBe(6.29)
        ->and($plan['week_count'])->toBe(28)
        ->and($plan['current'])->toBe([
            'phase_id' => W::CUT, 'title' => 'Fat loss', 'dates_text' => '31 Aug – 22 Nov', 'progress_text' => 'Week 7 of 12', 'progress' => 0.5,
        ]);
});

it('PLAN-003 a phase with no end runs past the last mesocycle', function () {
    $plan = planPayload(phases: [W::phase(W::CUT, 'maintenance', '2026-08-31', null, 0)]);

    expect($plan['phases'][0]['dates_text'])->toBe('31 Aug – no end')
        ->and($plan['week_count'])->toBe(17)
        ->and($plan['phases'][0]['weeks'])->toBe(17);
});

it('PLAN-005 a draft mesocycle has no band: only saved mesocycles are placed', function () {
    $plan = planPayload(mesocycles: [W::mesocycle(W::MESO1, 'Meso 1', W::CUT, '2026-08-31', 6)]);

    expect(array_column($plan['mesocycles'], 'id'))->toBe([W::MESO1]);
});

it('PLAN-006 only the current phase and the next are detailed; later ones are outlines', function () {
    expect(array_column(planPayload()['phases'], 'state'))->toBe(['current', 'future', 'outline']);
});

it('PLAN-006 a new phase starts where the last one ends, and none can follow a phase with no end', function () {
    expect(planPayload()['add_phase'])->toMatchArray(['starts_text' => 'Starts 15 Mar', 'refusal' => null, 'default_type' => 'maintenance']);

    $open = planPayload(phases: [W::phase(W::CUT, 'maintenance', '2026-08-31', null, 0)])['add_phase'];
    expect($open['refusal'])->toBe('A phase with no end cannot have phases after it.')
        ->and($open['default_type'])->toBe('muscleGain');
});

it('PLAN-007 training-only phases have no rate and their deloads say nothing about calories', function () {
    $plan = planPayload(trainingOnly: true);

    expect(array_column($plan['phases'], 'rate_text'))->toBe([null, null, null])
        ->and(array_unique(array_column($plan['deloads'], 'label')))->toBe(['Deload'])
        ->and(array_unique(array_column($plan['deloads'], 'diet_break_action')))->toBe(['none']);
});

it('PLAN-008 a plan starting mid-week has a short first week, and today sits on the same grid', function () {
    $plan = planPayload(planStart: '2026-09-02', phases: [W::phase(W::CUT, 'fatLoss', '2026-09-02', '2026-11-23', -0.75)], mesocycles: []);

    expect($plan['phases'][0]['start_week'])->toBe(0)
        ->and($plan['phases'][0]['weeks'])->toBe(12)
        ->and($plan['today_position'])->toBe(6.29);
});

it('PLAN-022 a Fat loss deload runs at maintenance calories as a diet break, and only one still ahead can be turned off', function () {
    $deloads = planPayload()['deloads'];

    expect($deloads[0])->toMatchArray(['label' => 'Deload · maintenance calories', 'marker' => 'deload', 'diet_break_action' => 'none'])
        ->and($deloads[1])->toMatchArray(['label' => 'Deload · maintenance calories', 'marker' => 'deload', 'diet_break_action' => 'turn_off']);
});

it('PLAN-009 a Mini-cut deload is a diet break like Fat loss', function () {
    $deloads = planPayload(phases: [W::phase(W::CUT, 'miniCut', '2026-10-12', '2026-11-23', -1)], mesocycles: [W::mesocycle(W::MESO2, 'Meso 2', W::CUT, '2026-10-12', 6)])['deloads'];

    expect($deloads[0])->toMatchArray(['week' => 11, 'label' => 'Deload · maintenance calories', 'diet_break_action' => 'turn_off']);
});

it('PLAN-024 Maintenance and Muscle gain deloads also run at maintenance, as planned weeks with nothing to turn off', function () {
    $deloads = planPayload(mesocycles: [
        W::mesocycle(W::MESO3, 'Meso 3', W::HOLD, '2026-11-23', 4),
        W::mesocycle(W::MESO1, 'Meso 4', W::GAIN, '2026-12-21', 5),
    ])['deloads'];

    expect(array_column($deloads, 'week'))->toBe([15, 20])
        ->and(array_unique(array_column($deloads, 'label')))->toBe(['Deload · maintenance calories'])
        ->and(array_unique(array_column($deloads, 'diet_break_action')))->toBe(['none']);
});

it('PLAN-022 a flagged deload week with no mesocycle deload is marked too', function () {
    $deloads = planPayload(weeks: [W::week('2026-10-26', deload: true)])['deloads'];

    expect(array_column($deloads, 'week'))->toBe([5, 8, 11, 15]);
});

it('PLAN-023 a deload whose diet break was turned off deloads in a deficit and offers to turn it back on', function () {
    $deloads = planPayload(weeks: [W::week('2026-11-16', dietBreakOff: true)])['deloads'];

    expect($deloads[1])->toMatchArray(['label' => 'Deload · deficit calories', 'marker' => 'deficit_deload', 'diet_break_action' => 'turn_on']);
});

it('PLAN-023 the warning comes before a diet break is turned off', function () {
    expect(planPayload()['diet_break_off_warning'])->toStartWith('Deloading in a deficit is one of the biggest mistakes RP warns about.');
});

it('PH-043 the timeline counts down to an event date, says tomorrow the day before, and drops a passed one', function () {
    $with = fn (string $event) => planPayload(phases: [
        W::phase(W::CUT, 'fatLoss', '2026-08-31', '2026-11-23', -0.75, event: $event),
        ...array_slice(W::phases(), 1),
    ]);

    expect($with('2026-12-05')['event_countdown'])->toBe('52 days to your event')
        ->and($with('2026-12-05')['phases'][0]['event_text'])->toBe('52 days to your event')
        ->and($with('2026-10-15')['event_countdown'])->toBe('Tomorrow is your event')
        ->and($with('2026-10-14')['event_countdown'])->toBe('Your event is today')
        ->and($with('2026-10-01')['event_countdown'])->toBeNull();
});

it('PH-045 suggests a short Maintenance when the plan had none in five months, and not otherwise', function () {
    $long = [W::phase(W::CUT, 'fatLoss', '2026-01-05', '2026-04-27', -0.75), W::phase(W::GAIN, 'muscleGain', '2026-04-27', '2026-12-28', 0.375)];

    expect(planPayload(planStart: '2026-01-05', phases: $long, mesocycles: [])['maintenance_suggestion'])
        ->toBe(['text' => 'No Maintenance in the last 5 months. Add a 4-week low-volume Maintenance phase?', 'weeks' => 4])
        ->and(planPayload()['maintenance_suggestion'])->toBeNull();
});
