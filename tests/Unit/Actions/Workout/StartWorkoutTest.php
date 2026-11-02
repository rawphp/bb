<?php

use App\Actions\Workout\StartWorkout;
use App\Capabilities\Reads\Workout\WorkoutPayload;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutIds;
use App\Support\Workout\WorkoutSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// The action's job is what it saves, so it runs on the database. Meso 2 runs 5 weeks from Monday 7 September; Monday
// 21 September is week 3, day 1 "Upper A" with Bench Press for 3 sets, and week 2's bench was logged at 81 and 82 kg.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function startSeed(): array
{
    Carbon::setTestNow('2026-09-21 18:00:00');
    Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => 5, 'rep_range_max' => 10,
    ]);
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    TrainSeed::trained($mesocycle, 2, 1, '2026-09-14');

    return [$user, $mesocycle];
}

function startIt(User $user, string $workoutId, string $actedAt = '2026-09-21 17:40:00'): ?string
{
    try {
        app(StartWorkout::class)($user, $workoutId, CarbonImmutable::parse($actedAt));

        return null;
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }
}

it('WO-010 OFF-021 starts the session at the instant the user acted, not the clock, with every planned set under the ids workout_show sent', function () {
    [$user, $mesocycle] = startSeed();
    $shown = WorkoutPayload::build(WorkoutSnapshot::load($user, $mesocycle->id, 3, 1))->toArray();

    expect(startIt($user, strtoupper($shown['workout_id'])))->toBeNull();

    $session = WorkoutSession::query()->sole();
    $block = WorkoutExercise::query()->sole();
    $sets = WorkoutSet::query()->where('workout_session_id', $session->id)->orderBy('position')->get();
    expect([$session->user_id, $session->mesocycle_id, $session->week, $session->day])->toBe([$user->id, $mesocycle->id, 3, 1])
        ->and($session->actual_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:40')
        ->and($session->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:30')
        ->and($session->planned_end_at->format('Y-m-d H:i'))->toBe('2026-09-21 18:30')
        ->and([$session->actual_end_at, $session->is_skipped])->toBe([null, false])
        ->and($block->id)->toBe($shown['exercises'][0]['id'])
        ->and([$block->user_id, $block->position, $block->exercise_id])->toBe([$user->id, 1, (int) $shown['exercises'][0]['exercise_id']])
        ->and($sets->pluck('id')->all())->toBe(array_column($shown['exercises'][0]['sets'], 'id'))
        ->and($sets->pluck('workout_exercise_id')->unique()->all())->toBe([$block->id])
        ->and($sets->pluck('position')->all())->toBe([1, 2, 3])
        ->and($sets->pluck('target_weight_kg')->all())->toBe([82.5, 82.5, 82.5])
        ->and($sets->pluck('target_reps')->all())->toBe([10, 9, 7])
        ->and($sets->pluck('type')->unique()->all())->toBe(['regular'])
        ->and($sets->pluck('is_logged')->unique()->all())->toBe([false]);

    $after = WorkoutPayload::build(WorkoutSnapshot::load($user, $mesocycle->id, 3, 1))->toArray();
    expect($after['status'])->toBe('in_progress')
        ->and($after['workout_id'])->toBe($shown['workout_id'])
        ->and($after['exercises'])->toBe($shown['exercises']);
});

it('SCH-051 starts a session that was only moved in place, keeping its planned times', function () {
    [$user, $mesocycle] = startSeed();
    $moved = WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1,
        'planned_start_at' => '2026-09-22 07:00:00', 'planned_end_at' => '2026-09-22 08:00:00',
    ]);

    expect(startIt($user, WorkoutIds::workout($mesocycle->id, 3, 1)))->toBeNull()
        ->and(WorkoutSession::query()->count())->toBe(1)
        ->and($moved->fresh()->actual_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:40')
        ->and($moved->fresh()->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-22 07:00')
        ->and(WorkoutSet::query()->where('workout_session_id', $moved->id)->count())->toBe(3);
});

it('WO-010 OFF-021 starting a session already under way changes nothing, so a replayed start is harmless', function () {
    [$user, $mesocycle] = startSeed();
    $id = WorkoutIds::workout($mesocycle->id, 3, 1);
    startIt($user, $id);
    $before = [WorkoutSession::query()->sole()->getAttributes(), WorkoutSet::query()->count(), WorkoutExercise::query()->count()];

    expect(startIt($user, $id, '2026-09-21 17:55:00'))->toBeNull()
        ->and([WorkoutSession::query()->sole()->getAttributes(), WorkoutSet::query()->count(), WorkoutExercise::query()->count()])->toBe($before);
});

it('WO-010 refuses to start a finished or skipped session, and changes nothing', function (array $row, string $message) {
    [$user, $mesocycle] = startSeed();
    WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1, ...$row]);

    expect(startIt($user, WorkoutIds::workout($mesocycle->id, 3, 1)))->toBe($message)
        ->and(WorkoutSet::query()->count())->toBe(2);
})->with([
    'finished' => [['actual_start_at' => '2026-09-21 17:30:00', 'actual_end_at' => '2026-09-21 18:30:00'], 'This workout is finished, so it can no longer be changed.'],
    'skipped' => [['is_skipped' => true], 'This workout was skipped, so it can no longer be changed.'],
]);

it('OFF-021 a workout id that names none of the user\'s sessions is not found', function () {
    [$user, $mesocycle] = startSeed();
    $stranger = TrainSeed::user();

    expect(fn () => startIt($user, WorkoutIds::workout($mesocycle->id, 6, 1)))->toThrow(ModelNotFoundException::class)
        ->and(fn () => startIt($stranger, WorkoutIds::workout($mesocycle->id, 3, 1)))->toThrow(ModelNotFoundException::class)
        ->and(WorkoutSession::query()->count())->toBe(1);
});
