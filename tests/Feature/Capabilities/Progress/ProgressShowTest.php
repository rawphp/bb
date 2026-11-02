<?php

use App\Models\Exercise;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Carbon;
use Tests\Support\ProgressSeed;
use Tests\Support\ScreenFixture;

// The HTTP seam of progress_show and its screen fixtures. The rules are unit tests (ProgressChartPayloadTest and
// its neighbours).

beforeEach(fn () => Carbon::setTestNow(ProgressSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

it('ANA-001 refuses progress_show when signed out', function () {
    $this->postJson('/capabilities/progress_show')->assertUnauthorized();
});

it('ANA-001 answers 422 naming scope for a scope that is neither phase nor plan', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/progress_show', ['scope' => 'week'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'scope');
});

it('ANA-001 ANA-002 ANA-003 ANA-004 ANA-005 ANA-006 ANA-007 ANA-009 ANA-010 shows a seeded plan with the clock fixed', function () {
    ['user' => $user] = ProgressSeed::world();

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect($data['empty'])->toBeNull()
        ->and($data['scopes'][0]['selected'])->toBeTrue()
        ->and($data['chart']['trend'][0]['date'])->toBe('2026-09-01')
        ->and($data['chart']['trend'])->toHaveCount(40)
        ->and(array_column($data['chart']['waist'], 'value'))->toBe([88.0, 87.5, 87.5, 87.0])
        ->and(array_column($data['summary'], 'value'))->toBe(['80.0 kg', '+0.0 kg', '76.8 kg'])
        ->and($data['share']['message'])->toBe('Fat loss: 80.0 kg to 80.0 kg (+0.0 kg) over 5 weeks')
        ->and($data['this_week']['calories']['value_label'])->toBe('2410/18550')
        ->and($data['this_week']['days'][0]['date'])->toBe('2026-10-10')
        ->and($data['review_card']['title'])->toBe('Weekly review available')
        ->and($data['steps']['bar']['value_label'])->toBe('6,240/10,000')
        ->and($data['phase_history'][0]['title'])->toBe('Maintenance')
        ->and(array_column($data['mesocycle_history'], 'title'))->toBe(['Cut A', 'Base'])
        ->and(array_column($data['mesocycle_history'][0]['sets'], 'label'))->toBe(['Chest', 'Back', 'Biceps'])
        ->and(array_column($data['exercises'], 'name'))->toBe(['Barbell Row', 'Bench Press', 'Curl']);
});

it('ANA-001 draws the whole plan when asked', function () {
    ['user' => $user] = ProgressSeed::world();

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show', ['scope' => 'plan'])->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect($data['scopes'][1]['selected'])->toBeTrue()
        ->and($data['chart']['trend'])->toHaveCount(40);
});

it('ONB-032 WT-012 shows the empty state under four weigh-ins in the empty fixture\'s shape', function () {
    ['user' => $user] = ProgressSeed::world();
    WeighIn::query()->where('user_id', $user->id)->where('weighed_at', '>', '2026-09-03 23:59:59')->delete();

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('progress_show.empty'), $data))->toBe([])
        ->and($data['empty'])->toBe(['message' => 'Log 4 weigh-ins to see your trend', 'action_title' => 'Log a weigh-in'])
        ->and($data['chart'])->toBeNull()
        ->and($data['share'])->toBeNull();
});

it('ANA-001 says the phase has no weigh-ins in the scope-empty fixture\'s shape', function () {
    ['user' => $user] = ProgressSeed::world();
    // Weigh-ins only before the fat-loss phase began on 1 September.
    WeighIn::query()->where('user_id', $user->id)->delete();
    foreach (range(0, 5) as $offset) {
        WeighIn::factory()->for($user)->create(['weight_kg' => 82, 'weighed_at' => date('Y-m-d', strtotime("2026-08-10 +{$offset} days")).' 07:00:00']);
    }

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('progress_show.scope_empty'), $data))->toBe([])
        ->and($data['scope_message'])->toBe('No weigh-ins in this phase yet')
        ->and($data['chart'])->toBeNull();
});

it('ANA-003 shows a user with no plan the empty fixture\'s shape without a week card', function () {
    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/progress_show')->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect($data['empty'])->not->toBeNull()
        ->and($data['this_week'])->toBeNull()
        ->and($data['review_card'])->toBeNull()
        ->and($data['exercises'])->toBe([]);
});

it('ANA-001 keeps another user\'s weigh-ins, steps, mesocycles and sets out of the tab', function () {
    ProgressSeed::stranger();
    $mine = ProgressSeed::planned(User::factory()->create());

    $data = $this->actingAs($mine)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    expect($data['empty'])->not->toBeNull()
        ->and($data['chart'])->toBeNull()
        ->and($data['steps'])->toBeNull()
        ->and($data['phase_history'])->toBe([])
        ->and($data['mesocycle_history'])->toBe([])
        ->and($data['exercises'])->toBe([]);
});

it('ANA-009 EX-013 lists the exercises by the latest session the user logged a set in, leaving out deleted sessions', function () {
    ['user' => $user, 'bench' => $bench, 'row' => $row, 'curl' => $curl] = ProgressSeed::world();
    // A later planned-only session, deleted: it must not make Bench the most recent.
    $later = WorkoutSession::factory()->for($user)->create(['actual_start_at' => null, 'planned_start_at' => '2026-10-12 09:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $later->id, 'exercise_id' => $bench->id, 'is_logged' => true, 'actual_weight_kg' => 60, 'actual_reps' => 8]);
    $later->delete();
    // A planned-only session after the others, with an unlogged set: Curl stays where it was.
    $planned = WorkoutSession::factory()->for($user)->create(['actual_start_at' => null, 'planned_start_at' => '2026-10-13 09:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $planned->id, 'exercise_id' => $curl->id, 'is_logged' => false]);
    $unusedLibrary = Exercise::factory()->create();

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    expect(array_column($data['exercises'], 'id'))->toBe([(string) $row->id, (string) $bench->id, (string) $curl->id])
        ->and(array_column($data['exercises'], 'id'))->not->toContain((string) $unusedLibrary->id);
});

it('ANA-005 shows no steps when none are stored for today', function () {
    ['user' => $user] = ProgressSeed::world();
    Step::query()->where('user_id', $user->id)->delete();

    expect($this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data.steps'))->toBeNull();
});
