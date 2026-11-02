<?php

use App\Capabilities\Support\ReadKey;
use App\Models\DayTarget;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The HTTP seam of workout_move and workout_time_set. The rules are MoveWorkoutTest's. Thursday 24 September 2026
// holds Lower A (week 3, day 2) at 5:30 PM; today is Tuesday 22nd.

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function workoutWrite(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SCH-038 refuses workout_move and workout_time_set when signed out', function (string $name, array $input) {
    workoutWrite(null, $name, $input)->assertUnauthorized();
})->with([
    'workout_move' => ['workout_move', ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5', 'week' => 3, 'day' => 2, 'date' => '2026-09-26']],
    'workout_time_set' => ['workout_time_set', ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5', 'week' => 3, 'day' => 2, 'time' => '12:00']],
]);

it('SCH-038 answers 404 for another user\'s mesocycle', function (string $name, array $input) {
    $theirs = TrainSeed::mesocycle(TrainSeed::user());

    workoutWrite(TrainSeed::user(), $name, ['mesocycle_id' => $theirs->id, 'week' => 3, 'day' => 2] + $input)->assertNotFound();
})->with([
    'workout_move' => ['workout_move', ['date' => '2026-09-26']],
    'workout_time_set' => ['workout_time_set', ['time' => '12:00']],
]);

it('SCH-038 answers 422 naming the bad field, and a refused move in plain words', function (string $name, array $input, ?string $field, ?string $message) {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-09-24');

    $response = workoutWrite($user, $name, ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2] + $input)->assertStatus(422);

    if ($field !== null) {
        $response->assertJsonPath('error.violations.0.field', $field);
    } else {
        expect($response->json('error.message'))->toBe($message)
            ->and(WorkoutSession::query()->count())->toBe(0);
    }
})->with([
    'workout_move to no date' => ['workout_move', ['date' => '26/09/2026'], 'date', null],
    'workout_move to the next plan week' => ['workout_move', ['date' => '2026-09-28'], null, 'A workout can only move within its plan week. Pick another day.'],
    'workout_time_set at 12.00' => ['workout_time_set', ['time' => '12.00'], 'time', null],
]);

it('SCH-038 SCH-056 CAP-013 a move re-targets both days with a toast, and its undo puts the days and the session back', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $thursday = SheetSeed::day($user, '2026-09-24');
    $saturday = SheetSeed::day($user, '2026-09-26', ['day_type' => 'rest']);
    $before = [$thursday->fresh()->only(['day_type', 'kcal', 'meals']), $saturday->fresh()->only(['day_type', 'kcal', 'meals'])];

    $response = workoutWrite($user, 'workout_move', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'date' => '2026-09-26'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Meals updated for your 5:30 PM workout');
    expect($response->json('data.invalidates'))->toContain(
        'today_show{"date":"2026-09-24"}', 'today_show{"date":"2026-09-26"}', 'day_optimize_show{"date":"2026-09-26"}',
        'day_redistribute_show{"date":"2026-09-24"}', 'day_adjust_show{"date":"2026-09-26"}',
        'week_show{"date":"2026-09-21"}', 'week_show{"date":"2026-09-27"}', 'week_show{}', 'train_show{}',
        ReadKey::for('workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2]),
    )->and(WorkoutSession::query()->count())->toBe(1);

    workoutWrite($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect([DayTarget::query()->find($thursday->id)->only(['day_type', 'kcal', 'meals']), DayTarget::query()->find($saturday->id)->only(['day_type', 'kcal', 'meals'])])->toEqual($before)
        ->and(WorkoutSession::query()->count())->toBe(0);
});

it('SCH-056 CAP-013 a new start re-targets its day, and undo puts it back', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $thursday = SheetSeed::day($user, '2026-09-24');
    $before = $thursday->fresh()->only(['kcal', 'meals']);

    $response = workoutWrite($user, 'workout_time_set', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'time' => '12:00'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Meals updated for your 12:00 PM workout');
    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-24"}', 'day_optimize_show{"date":"2026-09-24"}', 'week_show{"date":"2026-09-24"}',
        ReadKey::for('workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2]));

    workoutWrite($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect(DayTarget::query()->find($thursday->id)->only(['kcal', 'meals']))->toEqual($before)
        ->and(WorkoutSession::query()->count())->toBe(0);
});
