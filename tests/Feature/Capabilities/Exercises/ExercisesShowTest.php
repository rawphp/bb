<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;

// The HTTP seam of exercises_show and its screen fixtures. The rules are unit tests (ExercisesPayloadTest).

beforeEach(fn () => Carbon::setTestNow('2026-10-10 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function exercisesShowFor(User $user)
{
    return test()->actingAs($user)->postJson('/capabilities/exercises_show', []);
}

function exerciseLoggedOn(User $user, Exercise $exercise, string $at, bool $planned = false): WorkoutSet
{
    $session = WorkoutSession::factory()->for($user)->create($planned ? ['actual_start_at' => null, 'planned_start_at' => $at] : ['actual_start_at' => $at]);

    return WorkoutSet::factory()->logged()->create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id]);
}

it('EX-003 refuses exercises_show when signed out', function () {
    $this->postJson('/capabilities/exercises_show', [])->assertUnauthorized();
});

it('EX-003 answers 422 naming the field for an input it does not take', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/exercises_show', ['search' => 'bench'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'search');
});

it('EX-003 shows the library and the user\'s own custom exercises, never another user\'s', function () {
    $user = User::factory()->create();
    Exercise::factory()->create(['name' => 'Squat']);
    Exercise::factory()->custom($user)->create(['name' => 'My press']);
    Exercise::factory()->custom()->create(['name' => 'Their press']);

    $names = array_column(exercisesShowFor($user)->assertOk()->json('data.exercises'), 'name');

    expect($names)->toBe(['My press', 'Squat']);
});

it('EX-013 EX-001 shows a seeded library in the fixture\'s shape, with the newest use of each exercise', function () {
    $user = User::factory()->create();
    $bench = Exercise::factory()->create(['name' => 'Barbell Bench Press', 'video_url' => 'https://example.com/bench']);
    $fly = Exercise::factory()->create(['name' => 'Cable Fly', 'video_url' => 'javascript:alert(1)']);
    Exercise::factory()->create(['name' => 'Arnold Press']);
    exerciseLoggedOn($user, $bench, '2026-10-01 09:00:00');
    exerciseLoggedOn($user, $bench, '2026-10-08 09:00:00');
    exerciseLoggedOn($user, $fly, '2026-10-06 09:00:00', planned: true);

    $data = exercisesShowFor($user)->assertOk()->json('data');

    assertMatchesScreenFixture('exercises_show', $data);
    expect(array_column($data['exercises'], 'last_used_text', 'name'))->toBe([
        'Arnold Press' => 'Never used', 'Barbell Bench Press' => 'Last used 8 Oct 2026', 'Cable Fly' => 'Last used 6 Oct 2026',
    ])->and(array_column($data['exercises'], 'has_video', 'name'))->toBe(['Arnold Press' => false, 'Barbell Bench Press' => true, 'Cable Fly' => false]);
});

it('EX-013 leaves out sets that are not logged, deleted, in a deleted session, or another user\'s', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $bench = Exercise::factory()->create(['name' => 'Bench']);
    exerciseLoggedOn($user, $bench, '2026-10-01 09:00:00');
    WorkoutSet::query()->whereKey(exerciseLoggedOn($user, $bench, '2026-10-02 09:00:00')->id)->first()->delete();
    exerciseLoggedOn($user, $bench, '2026-10-03 09:00:00')->session->delete();
    exerciseLoggedOn($other, $bench, '2026-10-04 09:00:00');
    $unlogged = exerciseLoggedOn($user, $bench, '2026-10-05 09:00:00');
    $unlogged->update(['is_logged' => false]);

    expect(exercisesShowFor($user)->assertOk()->json('data.exercises.0.last_used_text'))->toBe('Last used 1 Oct 2026');
});

it('EX-003 shows the empty library in the empty fixture\'s shape', function () {
    $data = exercisesShowFor(User::factory()->create())->assertOk()->json('data');

    assertMatchesScreenFixture('exercises_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('exercises_show.empty'), $data))->toBe([])
        ->and($data['exercises'])->toBe([]);
});
