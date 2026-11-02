<?php

use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Support\TrainSeed;

// The HTTP seam of mesocycle_show and its screen fixtures. The rules are unit tests (MesocyclePayloadTest).

afterEach(fn () => Carbon::setTestNow());

it('MESO-040 refuses mesocycle_show when signed out', function () {
    $this->postJson('/capabilities/mesocycle_show', ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5'])->assertUnauthorized();
});

it('MESO-040 answers 404 for another user\'s mesocycle', function () {
    $theirs = TrainSeed::mesocycle(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/mesocycle_show', ['mesocycle_id' => $theirs->id])
        ->assertNotFound();
});

it('MESO-040 answers 422 naming the field when the mesocycle is missing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/mesocycle_show', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'mesocycle_id');
});

it('MESO-040 MESO-041 MESO-043 MESO-044 shows a saved mesocycle in fat loss, week 4 of 6', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $cut = TrainSeed::phase($user, 'fatLoss', '2026-08-31');
    $mesocycle = TrainSeed::mesocycle($user, ['weeks' => 6, 'phase_id' => $cut->id]);
    TrainSeed::trained($mesocycle, 1, 1, '2026-09-07');

    $data = $this->actingAs($user)->postJson('/capabilities/mesocycle_show', ['mesocycle_id' => $mesocycle->id])->assertOk()->json('data');

    assertMatchesScreenFixture('mesocycle_show', $data);
    expect($data['saved']['length_text'])->toBe('6 weeks · week 5 now')
        ->and($data['saved']['end']['diet_break_offer'])->toBe('You are in fat loss. Start a diet break next week?')
        ->and(array_column($data['saved']['weeks'][0]['days'], 'state'))->toBe(['done', 'missed'])
        ->and($data['saved']['weeks'][0]['muscle_sets'])->toBe([['muscle_title' => 'Chest', 'sets' => 2]])
        ->and(array_column($data['saved']['weeks'][1]['days'][1]['exercises'], 'name'))->toBe(['Back Squat', 'Exercise not chosen yet']);
});

it('MESO-041 a week\'s calories are the user\'s own targets, never another user\'s', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    DayTarget::factory()->for($user)->create(['date' => '2026-09-14', 'kcal' => 2400]);
    DayTarget::factory()->for(User::factory()->create())->create(['date' => '2026-09-15', 'kcal' => 3000]);

    $weeks = $this->actingAs($user)->postJson('/capabilities/mesocycle_show', ['mesocycle_id' => $mesocycle->id])->json('data.saved.weeks');

    expect(array_column($weeks, 'calories_text'))->toBe([null, '2,400 kcal/day', null, null, null]);
});

it('MESO-007 MESO-004 shows an empty draft at the start of the build', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    TrainSeed::phase($user, 'muscleGain', '2026-08-31');
    $draft = TrainSeed::mesocycle($user, ['name' => null, 'is_draft' => true, 'start_date' => null, 'weeks' => null, 'route' => 'builder', 'days' => [], 'muscle_priorities' => []]);

    $data = $this->actingAs($user)->postJson('/capabilities/mesocycle_show', ['mesocycle_id' => $draft->id])->assertOk()->json('data');

    assertMatchesScreenFixture('mesocycle_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('mesocycle_show.empty'), $data))->toBe([]);
    expect($data['build']['resume_step'])->toBe('builder')
        ->and($data['build']['start_text'])->toBe('12 Oct 2026')
        ->and($data['build']['phase_title'])->toBe('Muscle gain');
});

it('MESO-005 MESO-021 shows a draft with days, its labels, exercises and priority warnings', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    TrainSeed::phase($user, 'muscleGain', '2026-08-31');
    $draft = TrainSeed::mesocycle($user, [
        'name' => 'Hypertrophy Block 3', 'is_draft' => true, 'start_date' => '2026-10-12', 'route' => 'builder',
        'muscle_priorities' => ['chest' => 'emphasize', 'back' => 'emphasize', 'quads' => 'emphasize'],
    ]);
    Exercise::factory()->custom(User::factory()->create())->create(['name' => 'Somebody else\'s press']);

    $data = $this->actingAs($user)->postJson('/capabilities/mesocycle_show', ['mesocycle_id' => $draft->id])->assertOk()->json('data');

    assertMatchesScreenFixture('mesocycle_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('mesocycle_show.warning'), $data))->toBe([]);
    $names = collect($data['build']['exercise_options'])->pluck('exercises')->flatten(1)->pluck('name');
    expect(array_column($data['build']['days'], 'label'))->toBe(['Monday', 'Thursday'])
        ->and($data['build']['warnings'][0]['tone'])->toBe('warning')
        ->and($data['build']['can_pick_for_me'])->toBeTrue()
        ->and($data['build']['can_finish'])->toBeTrue()
        ->and($names)->toContain('Bench Press')
        ->and($names)->not->toContain('Somebody else\'s press');
});
