<?php

use App\Actions\Today\ResolveMissedSession;
use App\Actions\Workout\AddWorkoutExercise;
use App\Actions\Workout\FinishWorkout;
use App\Actions\Workout\LogWorkoutSet;
use App\Actions\Workout\RelabelWorkoutDay;
use App\Actions\Workout\RemoveWorkoutExercise;
use App\Actions\Workout\ReorderWorkoutExercise;
use App\Actions\Workout\SkipWorkout;
use App\Actions\Workout\SkipWorkoutSet;
use App\Actions\Workout\StartWorkout;
use App\Actions\Workout\SwapWorkoutExercise;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\MissedSessionResolveInput;
use App\Domain\Model\DayType;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutIds;
use App\Support\Workout\WorkoutSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The session actions of the workout screen (rp-clone WorkoutModel+Edit): add, remove, reorder and swap an exercise,
// relabel the day and skip the workout. They save rows, so they run on the database. Meso 2 runs 5 weeks from Monday
// 7 September; Monday 21 September is week 3. Upper A (day 1, Monday) is Bench Press, Barbell Row and Dumbbell Curl;
// Lower A (day 2, Thursday) is Back Squat.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

const SA_UPPER = '01JB6Q400A2B3C4D5E6F7G8H9J';

/** @return array{User, Mesocycle} */
function saWorld(string $now = '2026-09-21 17:45:00'): array
{
    Carbon::setTestNow($now);
    $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
    $row = TrainSeed::exercise('Barbell Row', MuscleGroup::Back);
    $curl = TrainSeed::exercise('Dumbbell Curl', MuscleGroup::Biceps);
    $squat = TrainSeed::exercise('Back Squat', MuscleGroup::Quads);
    TrainSeed::exercise('Incline Dumbbell Press', MuscleGroup::Chest);
    TrainSeed::exercise('Standing Calf Raise', MuscleGroup::Calves);
    // Loaded exercises, so every set has a weight to target and log.
    Exercise::query()->update(['load_type' => ExerciseLoadType::Dumbbell]);
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['days' => [
        ['id' => SA_UPPER, 'label' => 'Upper A', 'weekday' => 1, 'slots' => [
            ['id' => 'slot-bench', 'exercise_id' => $bench->id, 'muscle_group' => 'chest', 'sets' => 3, 'carried' => ['weight_kg' => 80.0, 'reps' => 10]],
            ['id' => 'slot-row', 'exercise_id' => $row->id, 'muscle_group' => 'back', 'sets' => 2],
            ['id' => 'slot-curl', 'exercise_id' => $curl->id, 'muscle_group' => 'biceps', 'sets' => 2],
        ]],
        ['id' => '01JB6Q410A2B3C4D5E6F7G8H9J', 'label' => 'Lower A', 'weekday' => 4, 'slots' => [
            ['id' => 'slot-squat', 'exercise_id' => $squat->id, 'muscle_group' => 'quads', 'sets' => 3],
        ]],
    ]]);

    return [$user, $mesocycle];
}

function saExercise(string $name): Exercise
{
    return Exercise::query()->where('name', $name)->sole();
}

/** Week 3 day 1, started at 17:40. */
function saStarted(User $user, Mesocycle $mesocycle, int $week = 3, int $day = 1): WorkoutSession
{
    return app(StartWorkout::class)($user, WorkoutIds::workout($mesocycle->id, $week, $day), CarbonImmutable::parse('2026-09-21 17:40:00'));
}

/** @return list<WorkoutExercise> the session's exercises in order */
function saBlocks(WorkoutSession $session): array
{
    return WorkoutExercise::query()->where('workout_session_id', $session->id)->orderBy('position')->get()->all();
}

/** @return list<string> the session's exercise names in order */
function saNames(WorkoutSession $session): array
{
    return array_map(fn (WorkoutExercise $block) => $block->exercise->name, saBlocks($session));
}

function saBlock(WorkoutSession $session, string $name): WorkoutExercise
{
    return collect(saBlocks($session))->sole(fn (WorkoutExercise $block) => $block->exercise->name === $name);
}

/** @return list<?int> the stored day's slots' exercise ids */
function saSlots(Mesocycle $mesocycle, int $day = 0): array
{
    return array_column($mesocycle->fresh()->dayRows()[$day]['slots'], 'exercise_id');
}

function saRefusal(Closure $write): ?string
{
    try {
        $write();

        return null;
    } catch (DomainException $refused) {
        return $refused->getMessage();
    }
}

function saNewId(): string
{
    return strtolower((string) Str::ulid());
}

// MARK: workout_exercise_add (WO-031, MESO-045)

it('WO-031 adding an exercise puts it last in the session with three regular sets, and leaves the mesocycle alone', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $id = saNewId();

    app(AddWorkoutExercise::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1), strtoupper($id), saExercise('Standing Calf Raise')->id, false);

    $added = WorkoutExercise::query()->findOrFail($id);
    $sets = $added->sets()->get();
    expect(saNames($session))->toBe(['Bench Press', 'Barbell Row', 'Dumbbell Curl', 'Standing Calf Raise'])
        ->and($added->position)->toBe(4)
        ->and($sets->pluck('position')->all())->toBe([1, 2, 3])
        ->and($sets->map(fn (WorkoutSet $set) => [$set->type, $set->variant, $set->is_logged, $set->is_skipped, $set->exercise_id])->unique()->values()->all())
        ->toBe([['regular', 'straight', false, false, saExercise('Standing Calf Raise')->id]])
        ->and(saSlots($mesocycle))->toBe([saExercise('Bench Press')->id, saExercise('Barbell Row')->id, saExercise('Dumbbell Curl')->id]);
});

it('WO-006 PROG-020 an added exercise is targeted like a planned one: from its last week, else empty', function () {
    [$user, $mesocycle] = saWorld();
    // Week 2's Upper A logged two sets of Incline Dumbbell Press at 30 kg for 10.
    $incline = saExercise('Incline Dumbbell Press');
    $last = WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => 2, 'day' => 1,
        'actual_start_at' => '2026-09-14 17:30:00', 'actual_end_at' => '2026-09-14 18:30:00']);
    $block = WorkoutExercise::factory()->create(['workout_session_id' => $last->id, 'exercise_id' => $incline->id]);
    foreach ([1, 2] as $position) {
        WorkoutSet::factory()->create(['workout_session_id' => $last->id, 'workout_exercise_id' => $block->id, 'exercise_id' => $incline->id,
            'position' => $position, 'actual_weight_kg' => 30, 'actual_reps' => 10, 'is_logged' => true]);
    }
    saStarted($user, $mesocycle);
    $workout = WorkoutIds::workout($mesocycle->id, 3, 1);

    app(AddWorkoutExercise::class)($user, $workout, $targeted = saNewId(), $incline->id, false);
    app(AddWorkoutExercise::class)($user, $workout, $fresh = saNewId(), saExercise('Standing Calf Raise')->id, false);

    $targets = WorkoutSet::query()->where('workout_exercise_id', $targeted)->orderBy('position')->get();
    expect($targets->pluck('target_weight_kg')->filter()->count())->toBe(3)
        ->and($targets->pluck('target_reps')->filter()->count())->toBe(3)
        ->and(WorkoutSet::query()->where('workout_exercise_id', $fresh)->pluck('target_weight_kg')->filter()->all())->toBe([]);
});

it('MESO-045 adding with Apply to all remaining weeks also gives that day a three-set slot for the weeks not yet started', function () {
    [$user, $mesocycle] = saWorld();
    saStarted($user, $mesocycle);
    $calf = saExercise('Standing Calf Raise');

    app(AddWorkoutExercise::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1), saNewId(), $calf->id, true);

    $day = $mesocycle->fresh()->dayRows()[0];
    $next = WorkoutSnapshot::forWorkout($user, WorkoutIds::workout($mesocycle->id, 4, 1));
    expect(array_column($day['slots'], 'exercise_id'))->toBe([saExercise('Bench Press')->id, saExercise('Barbell Row')->id, saExercise('Dumbbell Curl')->id, $calf->id])
        ->and(array_slice($day['slots'][3], 1))->toBe(['exercise_id' => $calf->id, 'muscle_group' => 'calves', 'sets' => 3, 'carried' => null])
        ->and(saSlots($mesocycle, 1))->toBe([saExercise('Back Squat')->id])
        // The day keeps its label as stored, and next week's Upper A plans the calf raise.
        ->and($mesocycle->fresh()->days[0]['label'])->toBe('Upper A')
        ->and(array_map(fn ($block) => $block->exercise->name, $next->blocks()))->toContain('Standing Calf Raise');
});

it('MESO-045 an exercise added to the remaining weeks leaves the weeks stored before this one as they ran', function () {
    [$user, $mesocycle] = saWorld();
    $before = $mesocycle->dayRows();
    $mesocycle->update(['earlier_weeks' => [$before, $before, $before]]);
    saStarted($user, $mesocycle);

    app(AddWorkoutExercise::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1), saNewId(), saExercise('Standing Calf Raise')->id, true);

    $weeks = $mesocycle->fresh()->earlierWeekRows();
    expect(count($weeks[0][0]['slots']))->toBe(3)
        ->and(count($weeks[1][0]['slots']))->toBe(3)
        // Week 3, stored too, is this session's week: it runs the change.
        ->and(count($weeks[2][0]['slots']))->toBe(4)
        ->and(count($mesocycle->fresh()->dayRows()[0]['slots']))->toBe(4);
});

it('WO-031 a replayed add changes nothing', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $workout = WorkoutIds::workout($mesocycle->id, 3, 1);
    $id = saNewId();

    app(AddWorkoutExercise::class)($user, $workout, $id, saExercise('Standing Calf Raise')->id, true);
    app(AddWorkoutExercise::class)($user, $workout, $id, saExercise('Standing Calf Raise')->id, true);

    expect(count(saBlocks($session)))->toBe(4)
        ->and(WorkoutSet::query()->where('workout_exercise_id', $id)->count())->toBe(3)
        ->and(count(saSlots($mesocycle)))->toBe(4);
});

it('WO-031 an exercise can be added only to a session under way', function () {
    [$user, $mesocycle] = saWorld();

    expect(saRefusal(fn () => app(AddWorkoutExercise::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1), saNewId(), saExercise('Standing Calf Raise')->id, true)))
        ->toBe('Start the workout first.')
        ->and(WorkoutExercise::query()->count())->toBe(0)
        ->and(count(saSlots($mesocycle)))->toBe(3);
});

// MARK: workout_exercise_remove (WO-022)

it('WO-022 removing an exercise deletes it with its sets, and the others close up', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $row = saBlock($session, 'Barbell Row');
    $sets = $row->sets()->pluck('id')->all();

    app(RemoveWorkoutExercise::class)($user, strtoupper($row->id));

    expect(saNames($session))->toBe(['Bench Press', 'Dumbbell Curl'])
        ->and(array_map(fn (WorkoutExercise $block) => $block->position, saBlocks($session)))->toBe([1, 2])
        ->and(WorkoutSet::query()->whereIn('id', $sets)->count())->toBe(0)
        ->and(count(saSlots($mesocycle)))->toBe(3);
});

it('WO-022 an exercise of a finished session cannot be removed', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $session->update(['actual_end_at' => '2026-09-21 18:40:00']);

    expect(saRefusal(fn () => app(RemoveWorkoutExercise::class)($user, saBlock($session, 'Barbell Row')->id)))
        ->toBe('This workout is finished, so it can no longer be changed.')
        ->and(count(saBlocks($session)))->toBe(3);
});

// MARK: workout_exercise_reorder (WO-022, WO-031, MESO-045)

it('WO-022 moving an exercise puts it at its new place in the session, and leaves the mesocycle alone', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $row = saBlock($session, 'Barbell Row');

    app(ReorderWorkoutExercise::class)($user, $row->id, 1, false);
    expect(saNames($session))->toBe(['Barbell Row', 'Bench Press', 'Dumbbell Curl']);

    app(ReorderWorkoutExercise::class)($user, $row->id, 3, false);
    expect(saNames($session))->toBe(['Bench Press', 'Dumbbell Curl', 'Barbell Row'])
        ->and(array_map(fn (WorkoutExercise $block) => $block->position, saBlocks($session)))->toBe([1, 2, 3])
        ->and(saSlots($mesocycle))->toBe([saExercise('Bench Press')->id, saExercise('Barbell Row')->id, saExercise('Dumbbell Curl')->id]);
});

it('MESO-045 WO-031 a reorder with Apply to all remaining weeks writes the new order to the day, its slots keeping their ids', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);

    app(ReorderWorkoutExercise::class)($user, saBlock($session, 'Dumbbell Curl')->id, 1, true);

    $slots = $mesocycle->fresh()->dayRows()[0]['slots'];
    expect(saNames($session))->toBe(['Dumbbell Curl', 'Bench Press', 'Barbell Row'])
        ->and(array_column($slots, 'id'))->toBe(['slot-curl', 'slot-bench', 'slot-row'])
        ->and($slots[1]['carried'])->toBe(['weight_kg' => 80.0, 'reps' => 10]);
});

it('WO-022 a place past the last exercise is refused, naming the position', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);

    $refused = null;
    try {
        app(ReorderWorkoutExercise::class)($user, saBlock($session, 'Bench Press')->id, 4, true);
    } catch (FieldRefused $refusal) {
        $refused = $refusal;
    }

    expect([$refused?->field, $refused?->getMessage()])->toBe(['position', 'This session has 3 exercises, so pick a place from 1 to 3.'])
        ->and(saNames($session))->toBe(['Bench Press', 'Barbell Row', 'Dumbbell Curl']);
});

it('WO-022 moving an exercise to the place it has changes nothing', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $stored = $mesocycle->fresh()->days;

    app(ReorderWorkoutExercise::class)($user, saBlock($session, 'Barbell Row')->id, 2, true);

    expect(saNames($session))->toBe(['Bench Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and($mesocycle->fresh()->days)->toBe($stored);
});

// MARK: exercise_swap (EX-008)

it('EX-008 a swap with nothing done gives the new exercise the block\'s place and sets, with its own targets', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $bench = saBlock($session, 'Bench Press');
    $sets = $bench->sets()->pluck('id')->all();
    $incline = saExercise('Incline Dumbbell Press');
    $new = saNewId();

    app(SwapWorkoutExercise::class)($user, $bench->id, strtoupper($new), $incline->id, false);

    $swapped = WorkoutExercise::query()->findOrFail($new);
    $moved = WorkoutSet::query()->whereIn('id', $sets)->orderBy('position')->get();
    expect(saNames($session))->toBe(['Incline Dumbbell Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and($swapped->position)->toBe(1)
        ->and(WorkoutExercise::query()->find($bench->id))->toBeNull()
        ->and($moved->pluck('workout_exercise_id')->unique()->all())->toBe([$new])
        ->and($moved->pluck('exercise_id')->unique()->all())->toBe([$incline->id])
        ->and($moved->pluck('position')->all())->toBe([1, 2, 3])
        // Incline press has no history and no carried load: its targets are empty (WO-006).
        ->and($moved->pluck('target_weight_kg')->filter()->all())->toBe([])
        ->and(saSlots($mesocycle)[0])->toBe(saExercise('Bench Press')->id);
});

it('EX-008 logged and skipped sets stay on the old exercise; the others move to a block for the new one right after it', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $bench = saBlock($session, 'Bench Press');
    $sets = $bench->sets()->pluck('id')->all();
    app(LogWorkoutSet::class)($user, $sets[0], 82.5, WeightUnit::Kg, 10, true);
    app(SkipWorkoutSet::class)($user, $sets[2], true);
    $new = saNewId();

    app(SwapWorkoutExercise::class)($user, $bench->id, $new, saExercise('Incline Dumbbell Press')->id, false);

    expect(saNames($session))->toBe(['Bench Press', 'Incline Dumbbell Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and($bench->sets()->pluck('id')->all())->toBe([$sets[0], $sets[2]])
        ->and($bench->sets()->pluck('position')->all())->toBe([1, 2])
        ->and(WorkoutSet::query()->findOrFail($sets[0])->actual_weight_kg)->toBe(82.5)
        ->and(WorkoutSet::query()->where('workout_exercise_id', $new)->pluck('id')->all())->toBe([$sets[1]])
        ->and(WorkoutSet::query()->findOrFail($sets[1])->only(['exercise_id', 'position', 'actual_weight_kg', 'actual_reps', 'is_logged']))
        ->toBe(['exercise_id' => saExercise('Incline Dumbbell Press')->id, 'position' => 1, 'actual_weight_kg' => null, 'actual_reps' => null, 'is_logged' => false]);
});

it('EX-008 with every set done there is nothing left to move', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $bench = saBlock($session, 'Bench Press');
    foreach ($bench->sets()->pluck('id') as $id) {
        app(SkipWorkoutSet::class)($user, $id, true);
    }

    app(SwapWorkoutExercise::class)($user, $bench->id, saNewId(), saExercise('Incline Dumbbell Press')->id, false);

    expect(saNames($session))->toBe(['Bench Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and($bench->sets()->count())->toBe(3);
});

it('EX-008 a swap for the rest of the mesocycle also changes the day\'s slot, without the old exercise\'s carried load', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $incline = saExercise('Incline Dumbbell Press');

    app(SwapWorkoutExercise::class)($user, saBlock($session, 'Bench Press')->id, saNewId(), $incline->id, true);

    $slot = $mesocycle->fresh()->dayRows()[0]['slots'][0];
    expect([$slot['id'], $slot['exercise_id'], $slot['muscle_group'], $slot['sets'], $slot['carried']])->toBe(['slot-bench', $incline->id, 'chest', 3, null])
        ->and(saSlots($mesocycle, 1))->toBe([saExercise('Back Squat')->id])
        ->and(saNames($session)[0])->toBe('Incline Dumbbell Press');
});

it('EX-008 only another exercise of the same muscle can take its place', function (string $name, string $message) {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);

    $refused = null;
    try {
        app(SwapWorkoutExercise::class)($user, saBlock($session, 'Bench Press')->id, saNewId(), saExercise($name)->id, true);
    } catch (FieldRefused $refusal) {
        $refused = $refusal;
    }

    expect([$refused?->field, $refused?->getMessage()])->toBe(['exercise_id', $message])
        ->and(saNames($session))->toBe(['Bench Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and(saSlots($mesocycle)[0])->toBe(saExercise('Bench Press')->id);
})->with([
    'another muscle' => ['Dumbbell Curl', 'Swap Bench Press for another chest exercise.'],
    'the same exercise' => ['Bench Press', 'That is the exercise already in this place.'],
]);

it('EX-008 a replayed swap changes nothing', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $bench = saBlock($session, 'Bench Press');
    $sets = $bench->sets()->pluck('id')->all();
    app(LogWorkoutSet::class)($user, $sets[0], 82.5, WeightUnit::Kg, 10, true);
    $new = saNewId();

    app(SwapWorkoutExercise::class)($user, $bench->id, $new, saExercise('Incline Dumbbell Press')->id, false);
    app(SwapWorkoutExercise::class)($user, $bench->id, $new, saExercise('Incline Dumbbell Press')->id, false);

    expect(saNames($session))->toBe(['Bench Press', 'Incline Dumbbell Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and(WorkoutSet::query()->where('workout_exercise_id', $new)->count())->toBe(2);
});

// MARK: workout_day_relabel (WO-031, MESO-045)

it('WO-031 relabelling renames the day for the whole mesocycle without starting the session', function () {
    [$user, $mesocycle] = saWorld();
    $mesocycle->update(['earlier_weeks' => [$mesocycle->dayRows()]]);

    app(RelabelWorkoutDay::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1), '  Push  ');

    $fresh = $mesocycle->fresh();
    expect(array_column($fresh->dayRows(), 'label'))->toBe(['Push', 'Lower A'])
        ->and(array_column($fresh->earlierWeekRows()[0], 'label'))->toBe(['Push', 'Lower A'])
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and(WorkoutSnapshot::forWorkout($user, WorkoutIds::workout($mesocycle->id, 3, 1))->status()->value)->toBe('not_started');
});

it('WO-031 a blank day name is refused naming the label, and the same name changes nothing', function () {
    [$user, $mesocycle] = saWorld();
    $stored = $mesocycle->fresh()->days;
    $workout = WorkoutIds::workout($mesocycle->id, 3, 1);

    $refused = null;
    try {
        app(RelabelWorkoutDay::class)($user, $workout, '   ');
    } catch (FieldRefused $refusal) {
        $refused = $refusal;
    }
    app(RelabelWorkoutDay::class)($user, $workout, 'Upper A');

    expect([$refused?->field, $refused?->getMessage()])->toBe(['label', 'Give the day a name.'])
        ->and($mesocycle->fresh()->days)->toBe($stored);
});

it('WO-031 the day of a finished session cannot be relabelled from it', function () {
    [$user, $mesocycle] = saWorld();
    saStarted($user, $mesocycle)->update(['actual_end_at' => '2026-09-21 18:40:00']);

    expect(saRefusal(fn () => app(RelabelWorkoutDay::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1), 'Push')))
        ->toBe('This workout is finished, so it can no longer be changed.')
        ->and($mesocycle->fresh()->dayRows()[0]['label'])->toBe('Upper A');
});

// MARK: workout_skip (WO-050, SCH-053, WO-051)

it('WO-050 skipping a workout not started saves its session skipped, with no sets', function () {
    [$user, $mesocycle] = saWorld('2026-09-21 09:00:00');

    $skipped = app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1));

    $row = WorkoutSession::query()->sole();
    expect($skipped['session']->is($row))->toBeTrue()
        ->and([$row->week, $row->day, $row->is_skipped, $row->actual_start_at])->toBe([3, 1, true, null])
        ->and($row->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:30')
        ->and($row->status()->value)->toBe('skipped')
        ->and(WorkoutSet::query()->count())->toBe(0);
});

it('WO-050 skipping a started workout marks its session skipped and keeps what was logged', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);
    $set = saBlock($session, 'Bench Press')->sets()->first();
    app(LogWorkoutSet::class)($user, $set->id, 82.5, WeightUnit::Kg, 10, true);

    app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1));

    expect($session->fresh()->status()->value)->toBe('skipped')
        ->and($session->fresh()->actual_start_at->format('H:i'))->toBe('17:40')
        ->and($set->fresh()->is_logged)->toBeTrue()
        ->and(WorkoutSession::query()->count())->toBe(1);
});

it('WO-050 a finished workout cannot be skipped, and skipping a skipped one again changes nothing', function () {
    [$user, $mesocycle] = saWorld();
    saStarted($user, $mesocycle)->update(['actual_end_at' => '2026-09-21 18:40:00']);

    expect(saRefusal(fn () => app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1))))
        ->toBe('This workout is finished, so it can no longer be changed.');

    app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 4, 1));
    app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 4, 1));
    expect(WorkoutSession::query()->where('week', 4)->sole()->is_skipped)->toBeTrue();
});

it('SCH-053 skipping today\'s workout makes today a rest day and re-targets its meals', function () {
    [$user, $mesocycle] = saWorld('2026-09-21 09:00:00');
    $monday = SheetSeed::day($user, '2026-09-21');

    $skipped = app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1));

    $day = $monday->fresh();
    expect($skipped['toast'])->toBe('Meals updated for the skipped workout')
        ->and($skipped['dates'])->toBe(['2026-09-21'])
        ->and($day->day_type)->toBe(DayType::Rest)
        ->and($day->kcal)->toBeLessThan(2600.0)
        ->and(collect($day->meals)->sum('kcal'))->toEqualWithDelta($day->kcal, 0.5);
});

it('SCH-053 a workout skipped after its day has passed leaves that day\'s targets as they were', function () {
    [$user, $mesocycle] = saWorld('2026-09-22 09:00:00');
    $monday = SheetSeed::day($user, '2026-09-21');
    $before = $monday->fresh()->only(['day_type', 'kcal', 'meals']);

    $skipped = app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1));

    expect($skipped['toast'])->toBeNull()
        ->and($monday->fresh()->only(['day_type', 'kcal', 'meals']))->toBe($before)
        ->and(WorkoutSession::query()->sole()->is_skipped)->toBeTrue();
});

it('WO-051 a skipped session is no longer missed: the missed-day prompt no longer asks about it', function () {
    [$user, $mesocycle] = saWorld('2026-09-22 09:00:00');

    app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1));

    expect(saRefusal(fn () => app(ResolveMissedSession::class)($user, new MissedSessionResolveInput($mesocycle->id, 3, 1, 'do_it_today', '2026-09-22'))))
        ->toBe(ResolveMissedSession::NOT_MISSED);
});

it('WO-050 a skipped session cannot be finished or changed after', function () {
    [$user, $mesocycle] = saWorld();
    $session = saStarted($user, $mesocycle);

    app(SkipWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1));

    expect(saRefusal(fn () => app(RemoveWorkoutExercise::class)($user, saBlock($session, 'Barbell Row')->id)))
        ->toBe('This workout was skipped, so it can no longer be changed.')
        ->and(saRefusal(fn () => app(FinishWorkout::class)($user, WorkoutIds::workout($mesocycle->id, 3, 1), CarbonImmutable::now())))->not->toBeNull();
});
