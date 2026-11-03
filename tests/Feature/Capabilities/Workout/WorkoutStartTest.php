<?php

use App\Actions\Mesocycles\SetMusclePriority;
use App\Capabilities\Support\HostDefinition;
use App\Domain\Model\MuscleGroup;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;

// The HTTP seam of workout_start. The rules are unit tests (StartWorkoutTest).

afterEach(fn () => Carbon::setTestNow());

function startOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/workout_start', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('WO-010 workout_start is neither destructive nor reversible, and names the reads it changes', function () {
    $definition = app(CapabilityRegistry::class)->get('workout_start');

    expect(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe(['workout_show', 'today_show', 'week_show', 'train_show', 'mesocycle_show']);
});

it('WO-010 refuses workout_start when signed out', function () {
    startOver(null, ['workout_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5'])->assertUnauthorized();
});

it('WO-010 answers 404 for another user\'s workout', function () {
    $theirs = TrainSeed::mesocycle(User::factory()->create());

    startOver(TrainSeed::user(), ['workout_id' => WorkoutIds::workout($theirs->id, 1, 1)])->assertNotFound();

    expect(WorkoutSession::query()->count())->toBe(0);
});

it('WO-010 answers 422 naming the field when the workout id is not a ULID', function () {
    startOver(TrainSeed::user(), ['workout_id' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'workout_id');
});

it('WO-010 starts the session and says which reads changed', function () {
    Carbon::setTestNow('2026-09-21 17:40:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    $response = startOver($user, ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1)])->assertOk();

    expect($response->json('data.invalidates'))->toBe([
        'workout_show{"day":1,"mesocycle_id":"'.$mesocycle->id.'","week":3}',
        'train_show{}',
        "mesocycle_show{\"mesocycle_id\":\"{$mesocycle->id}\"}",
        'today_show{"date":"2026-09-21"}',
        'today_show{}',
        ...array_map(fn (string $day) => 'week_show{"date":"2026-09-'.$day.'"}', ['21', '22', '23', '24', '25', '26', '27']),
        'week_show{}',
    ])
        ->and(WorkoutSession::query()->sole()->actual_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:40');
});

it('WO-010 SCH-022 a session started away from its planned day refreshes Today and the week strip of both days', function () {
    // Week 2 day 2, "Lower A", is planned for Thursday 17 September; the user starts it on Tuesday 22nd, a plan week on.
    Carbon::setTestNow('2026-09-22 07:10:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    $keys = startOver($user, ['workout_id' => WorkoutIds::workout($mesocycle->id, 2, 2)])->assertOk()->json('data.invalidates');

    expect($keys)->toContain('today_show{"date":"2026-09-17"}', 'today_show{"date":"2026-09-22"}', 'today_show{}', 'week_show{}')
        ->and(array_values(array_filter($keys, fn (string $key) => str_starts_with($key, 'week_show{"'))))->toBe(array_map(
            fn (string $date) => 'week_show{"date":"'.$date.'"}',
            ['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20',
                '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27'],
        ));
});

it('WO-010 SCH-051 starting a session moved to another day refreshes Today of the day it moved to', function () {
    // Week 3 day 1, "Upper A", is planned for Monday 21 September; missed_session_resolve moved it to Tuesday 22nd at
    // 07:00, and the user starts it on Monday evening after all.
    Carbon::setTestNow('2026-09-21 17:40:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1,
        'planned_start_at' => '2026-09-22 07:00:00', 'planned_end_at' => '2026-09-22 08:00:00', 'actual_start_at' => null,
    ]);

    $keys = startOver($user, ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1)])->assertOk()->json('data.invalidates');

    expect($keys)->toContain('today_show{"date":"2026-09-21"}', 'today_show{"date":"2026-09-22"}')
        ->and(WorkoutSession::query()->sole()->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-22 07:00');
});

it('MESO-034 workout_start writes no exercise of a muscle set Off', function () {
    Carbon::setTestNow('2026-09-21 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $row = TrainSeed::exercise('Barbell Row', MuscleGroup::Back);
    $days = $mesocycle->days;
    $days[0]['slots'][] = ['id' => '01JB6Q402A2B3C4D5E6F7G8H9J', 'exercise_id' => $row->id, 'muscle_group' => 'back', 'sets' => 3];
    $mesocycle->update(['days' => $days]);
    app(SetMusclePriority::class)($user, $mesocycle->id, MuscleGroup::Chest, null);

    // Week 4 day 1, Upper A, started on its day, Monday 28 September.
    Carbon::setTestNow('2026-09-28 17:30:00');
    startOver($user, ['workout_id' => WorkoutIds::workout($mesocycle->id, 4, 1)])->assertOk();

    expect(WorkoutExercise::query()->with('exercise')->get()->map(fn (WorkoutExercise $e) => $e->exercise->muscle_group->value)->all())
        ->toBe(['back']);
});
