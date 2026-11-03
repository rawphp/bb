<?php

use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Support\TrainSeed;

// The HTTP seam of exercise_show and its screen fixtures. The rules are unit tests (ExercisePayloadTest).

beforeEach(fn () => Carbon::setTestNow('2026-10-10 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function exerciseShowFor($test, User $user, Exercise|string $exercise)
{
    return $test->actingAs($user)->postJson('/capabilities/exercise_show', ['exercise_id' => $exercise instanceof Exercise ? (string) $exercise->id : $exercise]);
}

function exerciseLogged(User $user, Exercise $exercise, string $at, array $sets): void
{
    $session = WorkoutSession::factory()->for($user)->create(['actual_start_at' => $at]);
    foreach ($sets as $position => [$kg, $reps]) {
        WorkoutSet::factory()->logged($kg, $reps)->create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'position' => $position + 1]);
    }
}

it('EX-002 refuses exercise_show when signed out', function () {
    $this->postJson('/capabilities/exercise_show', ['exercise_id' => '1'])->assertUnauthorized();
});

it('EX-002 answers 404 for another user\'s custom exercise, an unknown one, and an id that is not plain digits', function () {
    $user = User::factory()->create();
    $theirs = Exercise::factory()->custom()->create();
    $visible = Exercise::factory()->create();

    exerciseShowFor($this, $user, $visible)->assertOk();
    exerciseShowFor($this, $user, $theirs)->assertNotFound();
    exerciseShowFor($this, $user, '999999')->assertNotFound();
    exerciseShowFor($this, $user, 'abc')->assertNotFound();
    exerciseShowFor($this, $user, "{$visible->id}abc")->assertNotFound();
    exerciseShowFor($this, $user, " {$visible->id}")->assertNotFound();
});

it('EX-002 answers 422 naming exercise_id when it is missing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/exercise_show', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'exercise_id');
});

it('EX-002 EX-007 EX-010 EX-012 shows a seeded exercise in the fixture\'s shape', function () {
    $user = TrainSeed::user();
    $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
    $bench->update(['load_type' => 'barbell', 'video_url' => 'https://example.com/bench', 'rep_range_min' => 5, 'rep_range_max' => 10]);
    TrainSeed::mesocycle($user);
    ExerciseNote::factory()->create(['user_id' => $user->id, 'exercise_id' => $bench->id, 'note' => 'Shoulder blades back.']);
    ExerciseLoadStep::factory()->create(['user_id' => $user->id, 'exercise_id' => $bench->id, 'step_kg' => 1.25]);
    exerciseLogged($user, $bench, '2026-10-01 09:00:00', [[80, 10], [80, 9]]);
    exerciseLogged($user, $bench, '2026-10-08 09:00:00', [[82.5, 9], [82.5, 8]]);

    $data = exerciseShowFor($this, $user, $bench)->assertOk()->json('data');

    assertMatchesScreenFixture('exercise_show', $data);
    expect($data['id'])->toBe((string) $bench->id)
        ->and($data['is_custom'])->toBeFalse()
        ->and($data['video_url'])->toBe('https://example.com/bench')
        ->and($data['rep_range_text'])->toBe('5–10 reps')
        ->and($data['pinned_note'])->toBe('Shoulder blades back.')
        ->and($data['load_step'])->toBe(['step' => ['value' => 1.25, 'unit' => 'kg', 'text' => '1.25 kg'], 'default_text' => '2.5 kg', 'unit' => 'kg'])
        ->and(array_column($data['history'], 'date_text'))->toBe(['Thu, 8 Oct 2026', 'Thu, 1 Oct 2026'])
        ->and(array_column($data['history'][0]['sets'], 'text'))->toBe(['82.5 kg × 9', '82.5 kg × 8'])
        ->and($data['history'][0]['best_text'])->toBe('Best e1RM 107.3 kg');
});

it('EX-002 EX-004 shows the user\'s own exercise, not in the mesocycle and never trained, in the empty fixture\'s shape', function () {
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);
    $custom = Exercise::factory()->custom($user)->create(['name' => 'Landmine Press (my gym)', 'video_url' => null, 'load_type' => 'barbell']);

    $data = exerciseShowFor($this, $user, $custom)->assertOk()->json('data');

    assertMatchesScreenFixture('exercise_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('exercise_show.empty'), $data))->toBe([])
        ->and($data['is_custom'])->toBeTrue()
        ->and($data['rep_range_text'])->toBeNull()
        ->and($data['pinned_note'])->toBeNull()
        ->and($data['load_step']['step'])->toBeNull()
        ->and($data['history'])->toBe([]);
});

it('EX-012 shows the load step in pounds, and no step for bodyweight', function () {
    $user = TrainSeed::user();
    Profile::query()->where('user_id', $user->id)->update(['weight_unit' => 'lb', 'micro_plates' => true]);
    $barbell = Exercise::factory()->create(['load_type' => 'barbell']);
    $pushUp = Exercise::factory()->create(['load_type' => 'bodyweight']);
    ExerciseLoadStep::factory()->create(['user_id' => $user->id, 'exercise_id' => $barbell->id, 'step_kg' => 4.5]);

    expect(exerciseShowFor($this, $user, $barbell)->assertOk()->json('data.load_step'))->toBe([
        'step' => ['value' => 9.92, 'unit' => 'lb', 'text' => '9.92 lb'], 'default_text' => '2.5 lb', 'unit' => 'lb',
    ])->and(exerciseShowFor($this, $user, $pushUp)->assertOk()->json('data.load_step'))->toBeNull();
});

it('EX-010 shows each user their own note, step and history of a shared exercise', function () {
    $user = TrainSeed::user();
    $other = TrainSeed::user();
    $bench = Exercise::factory()->create(['load_type' => 'barbell']);
    ExerciseNote::factory()->create(['user_id' => $other->id, 'exercise_id' => $bench->id, 'note' => 'Theirs']);
    ExerciseLoadStep::factory()->create(['user_id' => $other->id, 'exercise_id' => $bench->id, 'step_kg' => 3]);
    exerciseLogged($other, $bench, '2026-10-01 09:00:00', [[100, 5]]);

    $data = exerciseShowFor($this, $user, $bench)->assertOk()->json('data');

    expect($data['pinned_note'])->toBeNull()
        ->and($data['load_step']['step'])->toBeNull()
        ->and($data['history'])->toBe([]);
});

it('EX-007 gives a rep range only to an exercise in the current mesocycle, not one in a saved mesocycle that starts later, one an older mesocycle held, or one in another user\'s mesocycle', function () {
    $user = TrainSeed::user();
    $current = Exercise::factory()->create(['rep_range_min' => 10, 'rep_range_max' => 20]);
    $future = Exercise::factory()->create(['rep_range_min' => 10, 'rep_range_max' => 20]);
    $older = Exercise::factory()->create(['rep_range_min' => 10, 'rep_range_max' => 20]);
    $days = fn (Exercise $exercise) => [['id' => '01JB6Q400A2B3C4D5E6F7G8H9J', 'label' => 'Day', 'weekday' => 1, 'slots' => [
        ['id' => '01JB6Q401A2B3C4D5E6F7G8H9J', 'exercise_id' => $exercise->id, 'muscle_group' => 'chest', 'sets' => 3],
    ]]];
    TrainSeed::mesocycle($user, ['start_date' => '2026-08-03', 'weeks' => 4, 'days' => $days($older)]);
    TrainSeed::mesocycle($user, ['start_date' => '2026-09-07', 'days' => $days($current)]);
    TrainSeed::mesocycle($user, ['start_date' => '2026-11-02', 'days' => $days($future)]);
    // Another user's mesocycle, started after the user's own, is never the user's current one.
    TrainSeed::mesocycle(TrainSeed::user(), ['start_date' => '2026-10-05', 'days' => $days($older)]);

    expect(exerciseShowFor($this, $user, $current)->assertOk()->json('data.rep_range_text'))->toBe('10–20 reps')
        ->and(exerciseShowFor($this, $user, $future)->assertOk()->json('data.rep_range_text'))->toBeNull()
        ->and(exerciseShowFor($this, $user, $older)->assertOk()->json('data.rep_range_text'))->toBeNull();
});
