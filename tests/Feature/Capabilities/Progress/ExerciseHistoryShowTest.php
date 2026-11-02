<?php

use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Carbon;
use Tests\Support\ProgressSeed;
use Tests\Support\ScreenFixture;

// The HTTP seam of exercise_history_show and its screen fixtures. The rules are unit tests
// (ExerciseHistoryPayloadTest, EstimatedOneRepMaxTest).

beforeEach(fn () => Carbon::setTestNow(ProgressSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

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
});

it('ANA-009 answers 404 for an id with trailing or leading characters, though the number is an exercise the user can see', function () {
    $user = User::factory()->create();
    $visible = Exercise::factory()->create();

    historyOf($this, $user, $visible)->assertOk();
    historyOf($this, $user, "{$visible->id}abc")->assertNotFound();
    historyOf($this, $user, " {$visible->id}")->assertNotFound();
});

it('EX-002 shows a custom exercise\'s history to its owner only', function () {
    ['user' => $user] = ProgressSeed::world();
    $mesocycle = ProgressSeed::mesocycle($user, 'Own', Phase::query()->where('user_id', $user->id)->value('id'), '2026-10-01', 2);
    $custom = Exercise::factory()->custom($user)->create(['name' => 'My press', 'muscle_group' => MuscleGroup::Chest]);
    $session = ProgressSeed::session($user, $mesocycle, 1, '2026-10-09 09:00:00', [[$custom, 50, 10]]);

    $data = historyOf($this, $user, $custom)->assertOk()->json('data');
    historyOf($this, ProgressSeed::stranger(), $custom)->assertNotFound();

    expect($data['title'])->toBe('My press')
        ->and(array_column($data['sessions'], 'id'))->toBe([$session->id]);
});

it('EX-002 lists a session\'s sets by position, not by when they were logged', function () {
    ['user' => $user, 'bench' => $bench, 'benchSessions' => [$first]] = ProgressSeed::world();
    // Added after the others, at the top: an inserted warm-up.
    WorkoutSet::factory()->create([
        'workout_session_id' => $first->id, 'exercise_id' => $bench->id, 'position' => 0,
        'actual_weight_kg' => 40, 'actual_reps' => 12, 'is_logged' => true,
    ]);

    $labels = array_column(historyOf($this, $user, $bench)->assertOk()->json('data.sessions.1.sets'), 'label');

    expect($labels)->toBe(['40.0 kg × 12', '60.0 kg × 9', '62.5 kg × 8']);
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

it('EX-002 leaves out a set that names the viewer but sits in another user\'s session', function () {
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();
    $before = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');
    $theirs = WorkoutSession::factory()->for(User::factory()->create())->create(['actual_start_at' => '2026-10-09 18:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $theirs->id, 'user_id' => $user->id, 'exercise_id' => $bench->id, 'is_logged' => true, 'actual_weight_kg' => 100, 'actual_reps' => 10]);

    $after = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');

    expect($after)->toBe($before)
        ->and(array_column($after, 'id'))->not->toContain($theirs->id);
});

it('EX-002 leaves out a set in the viewer\'s session that names another user', function () {
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();
    $before = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');
    $session = WorkoutSession::factory()->for($user)->create(['actual_start_at' => '2026-10-09 18:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $session->id, 'user_id' => User::factory()->create()->id, 'exercise_id' => $bench->id, 'is_logged' => true, 'actual_weight_kg' => 100, 'actual_reps' => 10]);

    $after = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');

    expect($after)->toBe($before)
        ->and(array_column($after, 'id'))->not->toContain($session->id);
});
