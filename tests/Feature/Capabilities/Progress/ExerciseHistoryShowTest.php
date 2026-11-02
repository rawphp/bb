<?php

use App\Models\Exercise;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Tests\Support\ProgressSeed;
use Tests\Support\ScreenFixture;

// The HTTP seam of exercise_history_show and its screen fixtures. The rules are unit tests
// (ExerciseHistoryPayloadTest, EstimatedOneRepMaxTest).

function historyOf($test, User $user, Exercise|string $exercise)
{
    return $test->actingAs($user)->postJson('/capabilities/exercise_history_show', ['exercise_id' => $exercise instanceof Exercise ? (string) $exercise->id : $exercise]);
}

it('ANA-009 refuses exercise_history_show when signed out', function () {
    $this->postJson('/capabilities/exercise_history_show', ['exercise_id' => '1'])->assertUnauthorized();
});

it('ANA-009 answers 404 for another user\'s custom exercise, an unknown one and one that is not a number', function () {
    $user = User::factory()->create();
    $theirs = Exercise::factory()->custom()->create();

    historyOf($this, $user, $theirs)->assertNotFound();
    historyOf($this, $user, '999999')->assertNotFound();
    historyOf($this, $user, 'abc')->assertNotFound();
    historyOf($this, $user, '12abc')->assertNotFound();
});

it('ANA-009 answers 422 naming exercise_id when it is missing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/exercise_history_show', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'exercise_id');
});

it('ANA-009 EX-002 shows a seeded exercise in the fixture\'s shape, newest session first', function () {
    ['user' => $user, 'bench' => $bench, 'benchSessions' => [$first, $second]] = ProgressSeed::world();

    $data = historyOf($this, $user, $bench)->assertOk()->json('data');

    assertMatchesScreenFixture('exercise_history_show', $data);
    expect($data['title'])->toBe('Bench Press')
        ->and($data['subtitle'])->toBe('Chest')
        ->and(array_column($data['sessions'], 'id'))->toBe([$second->id, $first->id])
        ->and(array_column($data['sessions'], 'date_label'))->toBe(['Thu, 8 Oct 2026', 'Mon, 5 Oct 2026'])
        ->and(array_column($data['sessions'], 'best_label'))->toBe(['Best e1RM 82.3 kg', 'Best e1RM 79.2 kg'])
        ->and(array_column($data['sessions'][0]['sets'], 'label'))->toBe(['65.0 kg × 8', '65.0 kg × 7', '62.5 kg × 8'])
        ->and(array_column($data['sessions'][0]['sets'], 'is_best'))->toBe([true, false, false]);
});

it('ANA-009 shows an exercise never trained in the empty fixture\'s shape', function () {
    ['user' => $user] = ProgressSeed::world();

    $data = historyOf($this, $user, Exercise::factory()->create(['name' => 'Squat']))->assertOk()->json('data');

    assertMatchesScreenFixture('exercise_history_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('exercise_history_show.empty'), $data))->toBe([])
        ->and($data['sessions'])->toBe([]);
});

it('ANA-009 shows the user\'s weights in pounds when that is the unit', function () {
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();
    Profile::query()->where('user_id', $user->id)->update(['weight_unit' => 'lb']);

    $data = historyOf($this, $user, $bench)->assertOk()->json('data');

    expect($data['sessions'][0]['sets'][0]['label'])->toBe('143.3 lb × 8');
});

it('EX-002 leaves out unlogged and deleted sets, deleted sessions, other exercises and other users', function () {
    ['user' => $user, 'bench' => $bench, 'row' => $row] = ProgressSeed::world();
    $session = WorkoutSession::factory()->for($user)->create(['actual_start_at' => '2026-10-09 18:00:00']);
    $logged = ['workout_session_id' => $session->id, 'exercise_id' => $bench->id, 'is_logged' => true, 'actual_weight_kg' => 100, 'actual_reps' => 10];
    $kept = WorkoutSet::factory()->create([...$logged, 'position' => 1]);
    WorkoutSet::factory()->create([...$logged, 'position' => 2, 'is_logged' => false]);
    WorkoutSet::factory()->create([...$logged, 'position' => 3])->delete();
    WorkoutSet::factory()->create([...$logged, 'position' => 4, 'exercise_id' => $row->id]);
    $deleted = WorkoutSession::factory()->for($user)->create(['actual_start_at' => '2026-10-09 19:00:00']);
    WorkoutSet::factory()->create([...$logged, 'workout_session_id' => $deleted->id]);
    $deleted->delete();
    ProgressSeed::stranger();

    $data = historyOf($this, $user, $bench)->assertOk()->json('data');

    expect($data['sessions'])->toHaveCount(3)
        ->and($data['sessions'][0]['id'])->toBe($session->id)
        ->and(array_column($data['sessions'][0]['sets'], 'id'))->toBe([$kept->id])
        ->and(collect($data['sessions'])->pluck('id')->all())->not->toContain($deleted->id);
});

it('EX-002 shows each user only their own sets of a shared exercise', function () {
    $stranger = ProgressSeed::stranger();
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();

    $mine = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');
    $theirs = historyOf($this, $stranger, $bench)->assertOk()->json('data.sessions');

    expect($mine)->toHaveCount(2)
        ->and($theirs)->toHaveCount(1)
        ->and($theirs[0]['sets'][0]['label'])->toBe('220.5 lb × 10');
});
