<?php

use App\Actions\Workout\AddWorkoutSet;
use App\Actions\Workout\DeleteWorkoutSet;
use App\Actions\Workout\LogWorkoutSet;
use App\Actions\Workout\SetWorkoutExerciseNote;
use App\Actions\Workout\SetWorkoutSetType;
use App\Actions\Workout\SkipWorkoutSet;
use App\Actions\Workout\StartWorkout;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\WorkoutSetType;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Workout\WorkoutIds;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;

// The set and note writes save rows, so they run on the database. Week 3 day 1 of Meso 2 (Monday 21 September) is
// started: Bench Press, 3 sets planned at 82.5 kg for 10, 9 and 7.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: User, 1: WorkoutExercise, 2: list<string>} the user, the bench block and its set ids */
function setsSeed(string $loadType = 'barbell'): array
{
    Carbon::setTestNow('2026-09-21 18:00:00');
    Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::from($loadType), 'rep_range_min' => 5, 'rep_range_max' => 10,
    ]);
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    TrainSeed::trained($mesocycle, 2, 1, '2026-09-14');
    app(StartWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1), CarbonImmutable::parse('2026-09-21 17:40:00'));
    $block = WorkoutExercise::query()->sole();

    return [$user, $block, $block->sets()->pluck('id')->all()];
}

function refusalOf(Closure $write): ?string
{
    try {
        $write();

        return null;
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }
}

// MARK: workout_set_log

it('WO-003 WO-005 logging a set stores the weight and reps the user lifted', function () {
    [$user, , $ids] = setsSeed();

    $session = app(LogWorkoutSet::class)($user, strtoupper($ids[1]), 85.0, WeightUnit::Kg, 8, true);

    $set = WorkoutSet::query()->find($ids[1]);
    expect([$set->actual_weight_kg, $set->actual_reps, $set->is_logged])->toBe([85.0, 8, true])
        ->and([$set->target_weight_kg, $set->target_reps])->toBe([82.5, 9])
        ->and($session->week)->toBe(3);
});

it('WO-025 a logged set counts as a working set', function () {
    [$user, , $ids] = setsSeed();
    app(LogWorkoutSet::class)($user, $ids[0], 82.5, WeightUnit::Kg, 10, true);

    $snapshot = TrainSnapshot::load($user);
    $session = WorkoutSession::query()->where('week', 3)->sole();

    expect($snapshot->loggedSets($session)->pluck('id')->all())->toBe([$ids[0]]);
});

it('CAP-015 a weight typed in pounds is stored in kg', function () {
    [$user, , $ids] = setsSeed();

    app(LogWorkoutSet::class)($user, $ids[0], 185.0, WeightUnit::Lb, 10, true);

    expect(WorkoutSet::query()->find($ids[0])->actual_weight_kg)->toEqualWithDelta(83.9146, 1e-4);
});

it('WO-003 unticking Log keeps what was typed', function () {
    [$user, , $ids] = setsSeed();
    app(LogWorkoutSet::class)($user, $ids[0], 82.5, WeightUnit::Kg, 10, true);

    app(LogWorkoutSet::class)($user, $ids[0], 82.5, WeightUnit::Kg, 10, false);

    $set = WorkoutSet::query()->find($ids[0]);
    expect([$set->actual_weight_kg, $set->actual_reps, $set->is_logged])->toBe([82.5, 10, false]);
});

it('WO-003 a set needs its weight and reps before it can be logged, and a bodyweight set no weight', function () {
    [$user, , $ids] = setsSeed();

    expect(refusalOf(fn () => app(LogWorkoutSet::class)($user, $ids[0], null, null, 10, true)))->toBe('Enter the weight and reps first.')
        ->and(refusalOf(fn () => app(LogWorkoutSet::class)($user, $ids[0], 82.5, WeightUnit::Kg, null, true)))->toBe('Enter the weight and reps first.')
        ->and(WorkoutSet::query()->find($ids[0])->is_logged)->toBeFalse();
});

it('WO-003 a bodyweight set logs with no added load', function () {
    [$user, , $ids] = setsSeed('bodyweight');

    app(LogWorkoutSet::class)($user, $ids[0], 10.0, WeightUnit::Kg, 12, true);

    $set = WorkoutSet::query()->find($ids[0]);
    expect([$set->actual_weight_kg, $set->actual_reps, $set->is_logged])->toBe([null, 12, true]);
});

it('WO-020 a skipped set cannot be logged', function () {
    [$user, , $ids] = setsSeed();
    app(SkipWorkoutSet::class)($user, $ids[0], true);

    expect(refusalOf(fn () => app(LogWorkoutSet::class)($user, $ids[0], 82.5, WeightUnit::Kg, 10, true)))->toBe('Unskip this set to log it.');
});

it('WO-010 a set of a finished, skipped or not started session cannot change', function (array $session, string $message) {
    [$user, , $ids] = setsSeed();
    WorkoutSession::query()->where('week', 3)->sole()->update($session);
    $block = WorkoutExercise::query()->sole();

    expect(refusalOf(fn () => app(LogWorkoutSet::class)($user, $ids[0], 82.5, WeightUnit::Kg, 10, true)))->toBe($message)
        ->and(refusalOf(fn () => app(SkipWorkoutSet::class)($user, $ids[0], true)))->toBe($message)
        ->and(refusalOf(fn () => app(DeleteWorkoutSet::class)($user, $ids[0])))->toBe($message)
        ->and(refusalOf(fn () => app(SetWorkoutSetType::class)($user, $ids[0], WorkoutSetType::Myorep)))->toBe($message)
        ->and(refusalOf(fn () => app(AddWorkoutSet::class)($user, (string) Str::ulid(), $block->id, null, WorkoutSetType::RegularStraight)))->toBe($message)
        ->and(refusalOf(fn () => app(SetWorkoutExerciseNote::class)($user, $block->id, 'Slow eccentrics')))->toBe($message)
        ->and(WorkoutSet::query()->count())->toBe(5);
})->with([
    'finished' => [['actual_end_at' => '2026-09-21 18:40:00'], 'This workout is finished, so it can no longer be changed.'],
    'skipped' => [['is_skipped' => true], 'This workout was skipped, so it can no longer be changed.'],
    'not started' => [['actual_start_at' => null], 'Start the workout first.'],
]);

it('OFF-021 another user\'s set is not found', function () {
    [, , $ids] = setsSeed();

    expect(fn () => app(LogWorkoutSet::class)(User::factory()->create(), $ids[0], 82.5, WeightUnit::Kg, 10, true))->toThrow(ModelNotFoundException::class);
});

// MARK: workout_set_add

it('WO-020 add set below inserts a set starting from the one above, and renumbers', function () {
    [$user, $block, $ids] = setsSeed();
    app(LogWorkoutSet::class)($user, $ids[0], 85.0, WeightUnit::Kg, 9, true);
    $new = strtolower((string) Str::ulid());

    app(AddWorkoutSet::class)($user, $new, strtoupper($block->id), $ids[0], WorkoutSetType::RegularStraight);

    $sets = $block->sets()->get();
    $added = WorkoutSet::query()->find($new);
    expect($sets->pluck('id')->all())->toBe([$ids[0], $new, $ids[1], $ids[2]])
        ->and($sets->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and([$added->target_weight_kg, $added->target_reps, $added->is_logged, $added->type, $added->variant])->toBe([85.0, 9, false, 'regular', 'straight'])
        ->and([$added->user_id, $added->exercise_id, $added->workout_session_id])->toBe([$user->id, $block->exercise_id, $block->workout_session_id]);
});

it('WO-022 PROG-019 a set added from the exercise menu goes at the end with its type', function () {
    [$user, $block, $ids] = setsSeed();
    $new = strtolower((string) Str::ulid());

    app(AddWorkoutSet::class)($user, $new, $block->id, null, WorkoutSetType::MyorepMatch);

    $added = WorkoutSet::query()->find($new);
    expect($block->sets()->pluck('id')->all())->toBe([...$ids, $new])
        ->and([$added->type, $added->variant, $added->target_weight_kg, $added->target_reps])->toBe(['myorep_match', null, 82.5, 7]);
});

it('CAP-019 a set added twice under the same id is added once', function () {
    [$user, $block] = setsSeed();
    $new = strtolower((string) Str::ulid());

    app(AddWorkoutSet::class)($user, $new, $block->id, null, WorkoutSetType::RegularStraight);
    app(AddWorkoutSet::class)($user, $new, $block->id, null, WorkoutSetType::RegularStraight);

    expect($block->sets()->count())->toBe(4);
});

it('WO-020 a set is added below a set of the same exercise only', function () {
    [$user, $block] = setsSeed();
    $other = WorkoutExercise::factory()->create(['workout_session_id' => $block->workout_session_id, 'position' => 2]);
    $elsewhere = WorkoutSet::factory()->create(['workout_session_id' => $block->workout_session_id, 'workout_exercise_id' => $other->id]);

    expect(refusalOf(fn () => app(AddWorkoutSet::class)($user, (string) Str::ulid(), $block->id, $elsewhere->id, WorkoutSetType::RegularStraight)))
        ->toBe('That set is not in this exercise.')
        ->and($block->sets()->count())->toBe(3);
});

// MARK: workout_set_delete, workout_set_skip, workout_set_type_set

it('WO-020 PROG-019 delete set removes the row and renumbers the rest', function () {
    [$user, $block, $ids] = setsSeed();

    app(DeleteWorkoutSet::class)($user, $ids[0]);

    expect($block->sets()->pluck('id')->all())->toBe([$ids[1], $ids[2]])
        ->and($block->sets()->pluck('position')->all())->toBe([1, 2])
        ->and(WorkoutSet::withTrashed()->find($ids[0])->trashed())->toBeTrue();
});

it('WO-020 WO-025 skipping a set keeps the row, unlogs it, and it never counts as working; unskipping brings it back', function () {
    [$user, , $ids] = setsSeed();
    app(LogWorkoutSet::class)($user, $ids[0], 82.5, WeightUnit::Kg, 10, true);

    app(SkipWorkoutSet::class)($user, $ids[0], true);
    $skipped = WorkoutSet::query()->find($ids[0]);
    app(SkipWorkoutSet::class)($user, $ids[0], false);

    expect([$skipped->is_skipped, $skipped->is_logged])->toBe([true, false])
        ->and(WorkoutSet::query()->find($ids[0])->is_skipped)->toBeFalse();
});

it('WO-021 a set\'s type and variant are stored', function (WorkoutSetType $type, string $stored, ?string $variant) {
    [$user, , $ids] = setsSeed();

    app(SetWorkoutSetType::class)($user, $ids[0], $type);

    $set = WorkoutSet::query()->find($ids[0]);
    expect([$set->type, $set->variant])->toBe([$stored, $variant]);
})->with([
    'down' => [WorkoutSetType::RegularDown, 'regular', 'down'],
    'myorep' => [WorkoutSetType::Myorep, 'myorep', null],
    'myorep match' => [WorkoutSetType::MyorepMatch, 'myorep_match', null],
]);

// MARK: workout_exercise_note_set

it('WO-022 a session note is saved trimmed, and an empty or null note clears it', function () {
    [$user, $block] = setsSeed();

    app(SetWorkoutExerciseNote::class)($user, $block->id, "  Superset with face pulls \n");
    $saved = $block->fresh()->note;
    app(SetWorkoutExerciseNote::class)($user, $block->id, '   ');
    $blank = $block->fresh()->note;
    app(SetWorkoutExerciseNote::class)($user, $block->id, 'Slow eccentrics');
    app(SetWorkoutExerciseNote::class)($user, $block->id, null);

    expect([$saved, $blank, $block->fresh()->note])->toBe(['Superset with face pulls', null, null]);
});
