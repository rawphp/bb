<?php

use App\Actions\Mesocycles\EndMesocycle;
use App\Models\PlanWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// The action's job is what it saves, so it runs on the database. Meso 2 runs 5 weeks from Monday 7 September.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function endMeso(string $today, bool $dietBreak = false, string $phase = 'fatLoss', array $meso = []): array
{
    Carbon::setTestNow("{$today} 10:00:00");
    $user = TrainSeed::user();
    $phase = TrainSeed::phase($user, $phase, '2026-08-31');
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => $phase->id, ...$meso]);
    try {
        app(EndMesocycle::class)($user, $mesocycle->id, $dietBreak);
        $refusal = null;
    } catch (DomainException $refused) {
        $refusal = $refused->getMessage();
    }

    return [$mesocycle->fresh(), $refusal];
}

it('MESO-043 ends it today and changes nothing else', function () {
    [$mesocycle, $refusal] = endMeso('2026-09-23', phase: 'muscleGain');

    expect($refusal)->toBeNull()
        ->and($mesocycle->ended_on->format('Y-m-d'))->toBe('2026-09-23')
        ->and($mesocycle->weeks)->toBe(5)
        ->and($mesocycle->start_date->format('Y-m-d'))->toBe('2026-09-07')
        ->and(array_column($mesocycle->dayRows(), 'label'))->toBe(['Upper A', 'Lower A'])
        ->and(PlanWeek::query()->count())->toBe(0);
});

it('MESO-043 can end on its first day', function () {
    expect(endMeso('2026-09-07')[1])->toBeNull();
});

it('MESO-043 refuses a mesocycle that has not started or is over', function (string $today) {
    [$mesocycle, $refusal] = endMeso($today);

    expect($refusal)->toBe('Only a mesocycle that has started and is still running can be ended.')
        ->and($mesocycle->ended_on)->toBeNull();
})->with(['2026-09-01', '2026-10-12']);

it('MESO-044 a diet break makes next plan week\'s new row a diet break', function () {
    [$mesocycle, $refusal] = endMeso('2026-09-23', dietBreak: true);
    $week = PlanWeek::query()->sole();

    expect($refusal)->toBeNull()
        ->and($mesocycle->ended_on->format('Y-m-d'))->toBe('2026-09-23')
        ->and($week->user_id)->toBe($mesocycle->user_id)
        ->and($week->start_date->format('Y-m-d'))->toBe('2026-09-28')
        ->and([$week->is_diet_break, $week->is_deload, $week->is_away])->toBe([true, false, false]);
});

it('MESO-044 a diet break turns next plan week\'s row into one and keeps its other flags', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $phase = TrainSeed::phase($user, 'fatLoss', '2026-08-31');
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => $phase->id]);
    $row = PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-28', 'is_deload' => true]);

    app(EndMesocycle::class)($user, $mesocycle->id, true);

    expect(PlanWeek::query()->count())->toBe(1)
        ->and([$row->fresh()->is_diet_break, $row->fresh()->is_deload])->toBe([true, true]);
});

it('MESO-044 refuses a diet break the end does not offer, and ends nothing', function (string $today, string $phase) {
    [$mesocycle, $refusal] = endMeso($today, dietBreak: true, phase: $phase);

    expect($refusal)->toBe('A diet break is offered only when a fat loss mesocycle ends outside a diet break week.')
        ->and($mesocycle->ended_on)->toBeNull()
        ->and(PlanWeek::query()->count())->toBe(0);
})->with([
    'muscle gain' => ['2026-09-23', 'muscleGain'],
    // Week 5, the deload, is a fat loss diet break already.
    'in a diet break' => ['2026-10-06', 'fatLoss'],
]);

it('MESO-043 only touches the user\'s own row', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $past = TrainSeed::mesocycle($user, ['name' => 'Meso 1', 'start_date' => '2026-06-01']);
    $mesocycle = TrainSeed::mesocycle($user);
    $theirs = TrainSeed::mesocycle(TrainSeed::user());

    app(EndMesocycle::class)($user, $mesocycle->id, false);

    expect($mesocycle->fresh()->ended_on->format('Y-m-d'))->toBe('2026-09-23')
        ->and($past->fresh()->ended_on)->toBeNull()
        ->and($theirs->fresh()->ended_on)->toBeNull();
});

it('MESO-044 a diet break makes the user\'s own plan week one, never another user\'s row in the same week', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $other = TrainSeed::user();
    TrainSeed::phase($other, 'muscleGain', '2026-08-31');
    $theirs = PlanWeek::factory()->for($other)->create(['start_date' => '2026-09-28', 'is_deload' => true]);
    $before = $theirs->fresh()->getAttributes();
    $user = TrainSeed::user();
    $phase = TrainSeed::phase($user, 'fatLoss', '2026-08-31');
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => $phase->id]);

    app(EndMesocycle::class)($user, $mesocycle->id, true);
    $mine = PlanWeek::query()->where('user_id', $user->id)->sole();

    expect($theirs->fresh()->getAttributes())->toBe($before)
        ->and(PlanWeek::query()->count())->toBe(2)
        ->and($mine->start_date->format('Y-m-d'))->toBe('2026-09-28')
        ->and([$mine->is_diet_break, $mine->is_deload])->toBe([true, false]);
});
