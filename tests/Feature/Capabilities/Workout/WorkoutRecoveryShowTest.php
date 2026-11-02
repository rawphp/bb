<?php

use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;
use Tests\Support\WorkoutSeed;

// workout_show with a recovery flag on, through the HTTP seam and against the screen contract. The rules are
// WorkoutRecoveryTest.

afterEach(fn () => Carbon::setTestNow());

it('PROG-051 workout_show sends the recovery note with the held weight, as the screen contract has it', function () {
    Carbon::setTestNow('2026-09-21 09:00:00');
    Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => 5, 'rep_range_max' => 30,
    ]);
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    WorkoutSeed::started($mesocycle, 1, 1, '2026-09-07 17:30:00', reps: 7);
    WorkoutSeed::started($mesocycle, 2, 1, '2026-09-14 17:30:00', reps: 7);

    $data = test()->actingAs($user)->postJson('/capabilities/workout_show', ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])
        ->assertOk()->json('data');

    assertMatchesScreenFixture('workout_show', $data);
    expect($data['exercises'][0]['recovery_note'])->toBe([
        'text' => 'Chest missed its rep targets 2 sessions running. Hold sets and repeat the weight to recover.',
        'tone' => 'neutral',
    ])->and(array_map(fn (array $set) => $set['weight']['text'], $data['exercises'][0]['sets']))->toBe(['80 kg', '80 kg', '80 kg']);
});
