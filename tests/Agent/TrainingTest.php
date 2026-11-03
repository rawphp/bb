<?php

use App\Domain\Model\MuscleGroup;
use App\Models\WorkoutSession;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Agent\Any;
use Tests\Agent\Coach;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// Eval requests about workouts (AGT-050). TrainSeed's Meso 2 runs five weeks from Monday 7 September 2026: Upper A
// (Bench Press) on Mondays, Lower A (Back Squat, calves) on Thursdays, both at 5:30 PM. Week 3 starts 21 September.
uses()->group('warp-isolated');

afterEach(fn () => Carbon::setTestNow());

it('AGT-050 "move tomorrow\'s workout to Saturday"', function () {
    Carbon::setTestNow('2026-09-23 08:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-09-24');
    SheetSeed::day($user, '2026-09-26', ['day_type' => 'rest']);

    Coach::says($user, "Move tomorrow's workout to Saturday", calls: [
        ['workout_move', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'date' => '2026-09-26']],
    ]);

    expect(WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->value('planned_start_at')?->format('Y-m-d H:i'))->toBe('2026-09-26 17:30');
});

it('AGT-050 "swap bench for dumbbell press"', function () {
    Carbon::setTestNow('2026-09-21 17:40:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $dumbbell = TrainSeed::exercise('Dumbbell Bench Press', MuscleGroup::Chest);
    test()->actingAs($user)->postJson('/capabilities/workout_start', ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1)], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
    $bench = test()->actingAs($user)->postJson('/capabilities/workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.exercises.0.id');

    Coach::says($user, 'Swap bench for dumbbell press', calls: [
        ['exercise_swap', ['workout_exercise_id' => $bench, 'new_workout_exercise_id' => Any::id(), 'exercise_id' => (string) $dumbbell->id, 'scope' => new Any('rest_of_mesocycle')]],
    ], reply: 'Swapped Bench Press for Dumbbell Bench Press in Upper A, from this week on.');

    $names = test()->actingAs($user)->postJson('/capabilities/workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.exercises.*.name');
    expect($names)->toBe(['Dumbbell Bench Press']);
});

it('AGT-050 "push Thursday\'s workout to 7pm", then "undo that"', function () {
    Carbon::setTestNow('2026-09-22 08:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-09-24');

    $moved = Coach::says($user, "Push Thursday's workout to 7pm", calls: [
        ['workout_time_set', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'time' => '19:00']],
    ], reply: 'Thursday\'s Lower A now starts at 7:00 PM.');

    Coach::says($user, 'Undo that', calls: [
        ['undo_apply', ['token' => $moved->result('workout_time_set')['data']['undo']]],
    ], reply: 'Undone: Lower A is back at 5:30 PM.');

    expect(WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->count())->toBe(0);
});
