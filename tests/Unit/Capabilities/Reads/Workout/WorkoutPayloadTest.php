<?php

use App\Capabilities\Reads\Workout\WorkoutPayload;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutIds;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Unit\Capabilities\Reads\Workout\WorkoutWorld as W;

// The workout screen (rp-clone WorkoutModel, WorkoutScreenTests), from in-memory rows. Today is Monday 28 September
// at 17:35, week 3 day 1 of "Meso 2", unless a test says otherwise.

function woShow(mixed ...$world): array
{
    return WorkoutPayload::build(W::snapshot(...$world))->toArray();
}

function woExercise(array $payload, string $name): array
{
    foreach ($payload['exercises'] as $exercise) {
        if ($exercise['name'] === $name) {
            return $exercise;
        }
    }

    throw new RuntimeException("No {$name} in the workout");
}

/** @return list<?float> */
function woWeights(array $exercise): array
{
    return array_map(fn (array $set) => $set['weight']['value'] ?? null, $exercise['sets']);
}

/** @return list<?int> */
function woReps(array $exercise): array
{
    return array_column($exercise['sets'], 'reps');
}

/** Week 3 day 1 started at 17:35, with its three exercises' rows as workout_start writes them. */
function woStarted(array $sets, array $world = []): array
{
    $workout = WorkoutIds::workout(W::MESO, 3, 1);

    return [
        'sessions' => [...W::sessions(), W::session('m2w3d1', 3, 1, '2026-09-28 17:35:00')],
        'sets' => [...W::sets(), ...$sets],
        'blocks' => [
            W::block(WorkoutIds::block($workout, 'slot-12'), 'm2w3d1', W::BENCH, 1),
            W::block(WorkoutIds::block($workout, 'slot-25'), 'm2w3d1', W::ROW, 2),
            W::block(WorkoutIds::block($workout, 'slot-40'), 'm2w3d1', W::CURL, 3),
        ],
        // Only week 2 had started by then.
        'history' => [W::sessions()[0]],
        ...$world,
    ];
}

/** A set row of a started week 3 day 1 block, as workout_start writes it. */
function woRow(string $slot, int $index, int $exercise, array $attributes = []): WorkoutSet
{
    $workout = WorkoutIds::workout(W::MESO, 3, 1);

    return W::set(WorkoutIds::set($workout, $slot, $index), 'm2w3d1', $exercise, $index + 1, [
        'workout_exercise_id' => WorkoutIds::block($workout, $slot), ...$attributes,
    ]);
}

// MARK: WO-001, WO-002

it('WO-001 shows the title with week, day, label and date, then the day\'s exercises in order with their sets', function () {
    $payload = woShow();

    expect($payload['title'])->toBe('Week 3 · Day 1 · Upper A · Mon 28 Sep')
        ->and($payload['day_label'])->toBe('Upper A')
        ->and($payload['status'])->toBe('not_started')
        ->and($payload['is_editable'])->toBeTrue()
        ->and($payload['mesocycle_id'])->toBe(W::MESO)
        ->and($payload['workout_id'])->toBe(WorkoutIds::workout(W::MESO, 3, 1))
        ->and(array_column($payload['exercises'], 'name'))->toBe(['Bench Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and(array_map(fn (array $e) => count($e['sets']), $payload['exercises']))->toBe([3, 3, 2])
        ->and(array_column($payload['exercises'][0]['sets'], 'number'))->toBe([1, 2, 3]);
});

it('WO-001 leaves the date out of the title when the plan puts the session on no day', function () {
    $payload = woShow(meso: ['days' => array_map(fn (array $day) => [...$day, 'weekday' => null], W::days())], profile: ['training_days' => []]);

    expect($payload['title'])->toBe('Week 3 · Day 1 · Upper A');
});

it('WO-001 a week or day outside the mesocycle is not found', function (int $week, int $day) {
    expect(fn () => W::snapshot(week: $week, day: $day))->toThrow(ModelNotFoundException::class);
})->with([[0, 1], [6, 1], [3, 0], [3, 3]]);

it('WO-002 each header shows the name, the equipment and rep range, and the pinned note', function () {
    $payload = woShow(notes: [(new ExerciseNote)->forceFill(['user_id' => 1, 'exercise_id' => W::BENCH, 'note' => 'Retract the shoulder blades'])]);
    $bench = woExercise($payload, 'Bench Press');

    expect($bench['detail'])->toBe('Barbell · 5–10 reps')
        ->and($bench['exercise_id'])->toBe((string) W::BENCH)
        ->and($bench['muscle_group'])->toBe('chest')
        ->and($bench['pinned_note'])->toBe('Retract the shoulder blades')
        ->and(woExercise($payload, 'Barbell Row')['pinned_note'])->toBeNull()
        ->and(woExercise($payload, 'Dumbbell Curl')['detail'])->toBe('Dumbbell · 10–20 reps')
        ->and([$bench['can_move_up'], $bench['can_move_down']])->toBe([false, true])
        ->and([woExercise($payload, 'Dumbbell Curl')['can_move_up'], woExercise($payload, 'Dumbbell Curl')['can_move_down']])->toBe([true, false]);
});

// MARK: WO-003, WO-004, PROG-020 to PROG-027

it('WO-003 WO-004 PROG-020 PROG-021 from week 2 each set is filled with targets built from last week', function () {
    $bench = woExercise(woShow(), 'Bench Press');

    // 80 kg raised 2% is 81.6, which rounds to 82.5 on a 2.5 kg step.
    expect(woWeights($bench))->toBe([82.5, 82.5, 82.5])
        ->and(woReps($bench))->toBe([10, 9, 8])
        ->and(array_map(fn (array $set) => $set['target_weight']['value'], $bench['sets']))->toBe([82.5, 82.5, 82.5])
        ->and($bench['sets'][0]['target_weight']['text'])->toBe('82.5 kg')
        ->and(array_column($bench['sets'], 'target_text'))->toBe(['82.5 kg × 10', '82.5 kg × 9', '82.5 kg × 8'])
        ->and(array_column($bench['sets'], 'is_logged'))->toBe([false, false, false])
        ->and(array_column($bench['sets'], 'set_type'))->toBe(['regular_straight', 'regular_straight', 'regular_straight']);
});

it('PROG-022 when rounding leaves the weight unchanged, each set gains a rep', function () {
    $curl = woExercise(woShow(), 'Dumbbell Curl');

    // 14 kg raised 2% is 14.28, which rounds back to 14 on a 2 kg step.
    expect(woWeights($curl))->toBe([14.0, 14.0])
        ->and(woReps($curl))->toBe([13, 11]);
});

it('PROG-027 past the top of the range the weight goes up a step and reps reset to the middle', function () {
    $row = woExercise(woShow(), 'Barbell Row');

    expect(woWeights($row))->toBe([62.5, 62.5, 62.5])
        ->and(woReps($row))->toBe([7, 6, 5]);
});

it('PROG-021 PROG-023 fat loss rises by 1% on the smallest step', function () {
    // 80 kg raised 1% is 80.8, which rounds to 81.25 on the 1.25 kg step fat loss uses.
    expect(woWeights(woExercise(woShow(phase: 'fatLoss'), 'Bench Press')))->toBe([81.25, 81.25, 81.25]);
});

it('PROG-020 PROG-026 a set count beyond last week\'s keeps dropping reps', function () {
    $days = W::days();
    $days[0]['slots'][0]['sets'] = 4;

    expect(woReps(woExercise(woShow(meso: ['days' => $days]), 'Bench Press')))->toBe([10, 9, 8, 6]);
});

it('PROG-020 history follows the exercise, not the slot', function () {
    $days = W::days();
    $days[0]['slots'] = [W::slot(W::CURL, 'biceps', 2), W::slot(W::BENCH, 'chest', 3)];
    $payload = woShow(meso: ['days' => $days]);

    expect(array_column($payload['exercises'], 'name'))->toBe(['Dumbbell Curl', 'Bench Press'])
        ->and(woWeights(woExercise($payload, 'Bench Press')))->toBe([82.5, 82.5, 82.5])
        ->and(woReps(woExercise($payload, 'Dumbbell Curl')))->toBe([13, 11]);
});

it('WO-025 PROG-020 only logged sets that were not skipped count as last week\'s working sets', function () {
    $sets = [
        ...W::sets(),
        W::set('w2-bench-skipped', 'm2w2d1', W::BENCH, 20, ['actual_weight_kg' => 120, 'actual_reps' => 3, 'is_logged' => true, 'is_skipped' => true]),
        W::set('w2-bench-typed', 'm2w2d1', W::BENCH, 21, ['actual_weight_kg' => 120, 'actual_reps' => 3]),
    ];

    expect(woReps(woExercise(woShow(sets: $sets), 'Bench Press')))->toBe([10, 9, 8]);
});

it('PROG-021 CAP-015 targets are worked and shown in the pounds the user sees', function () {
    $bench = woExercise(woShow(profile: ['weight_unit' => 'lb']), 'Bench Press');

    // 80 kg is 176.4 lb; 2% more is 179.9, which rounds to 180 lb on a 5 lb step.
    expect($bench['sets'][0]['weight'])->toBe(['value' => 180.0, 'unit' => 'lb', 'text' => '180 lb'])
        ->and($bench['weight_label'])->toBe('Weight (lb)')
        ->and($bench['sets'][0]['rep_table'][0]['weight']['unit'])->toBe('lb');
});

it('PROG-027 a load step of the exercise replaces the default', function () {
    $step = (new ExerciseLoadStep)->forceFill(['user_id' => 1, 'exercise_id' => W::BENCH, 'step_kg' => 5.0]);
    $bench = woExercise(woShow(loadSteps: [$step]), 'Bench Press');

    // 81.6 rounds back to 80 on a 5 kg step; one more rep passes the range, so one step up and back to the middle.
    expect(woWeights($bench))->toBe([85.0, 85.0, 85.0])
        ->and(woReps($bench))->toBe([7, 6, 5]);
});

// MARK: WO-005, PROG-024, PROG-025, CAP-016

it('WO-005 PROG-024 each set carries a rep table, so a changed weight shows reps for an equal effort', function () {
    $set = woExercise(woShow(), 'Bench Press')['sets'][0];
    $table = array_column(array_map(fn (array $row) => [$row['weight']['text'], $row['reps']], $set['rep_table']), 1, 0);

    // 82.5 kg x 10 at 1 RIR; 15% either side is 72.5 to 92.5 kg.
    expect(array_key_first($table))->toBe('72.5 kg')
        ->and(array_key_last($table))->toBe('92.5 kg')
        ->and($table['82.5 kg'])->toBe(10)
        ->and($table['85 kg'])->toBe(8)
        ->and($table['77.5 kg'])->toBe(12)
        ->and($set['off_table_text'])->toBe('1 RIR, just log reps')
        ->and($set['reps_placeholder'])->toBe('1 RIR');
});

it('WO-003 WO-005 a started session is rebuilt from its rows, a changed weight showing its equal-effort reps', function () {
    $payload = woShow(...woStarted([
        woRow('slot-12', 0, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 10, 'actual_weight_kg' => 82.5, 'actual_reps' => 10, 'is_logged' => true]),
        woRow('slot-12', 1, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 9, 'actual_weight_kg' => 85, 'actual_reps' => 8]),
        woRow('slot-12', 2, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 8]),
        woRow('slot-12', 3, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 8, 'actual_weight_kg' => 80]),
        woRow('slot-40', 0, W::CURL, ['target_weight_kg' => 14, 'target_reps' => 13, 'is_skipped' => true]),
    ]));
    $bench = woExercise($payload, 'Bench Press');

    expect($payload['status'])->toBe('in_progress')
        ->and(array_column($payload['exercises'], 'name'))->toBe(['Bench Press', 'Barbell Row', 'Dumbbell Curl'])
        ->and(woWeights($bench))->toBe([82.5, 85.0, 82.5, 80.0])
        // The fourth set holds 80 kg with no reps typed: 82.5 x 8 at 1 RIR is 9 at 80 kg.
        ->and(woReps($bench))->toBe([10, 8, 8, 9])
        ->and(array_column($bench['sets'], 'is_logged'))->toBe([true, false, false, false])
        ->and(woExercise($payload, 'Barbell Row')['sets'])->toBe([])
        ->and(woExercise($payload, 'Dumbbell Curl')['sets'][0]['is_skipped'])->toBeTrue();
});

it('PROG-025 a weight far from last week\'s keeps only the RIR when the screen is rebuilt', function () {
    $payload = woShow(...woStarted([
        woRow('slot-12', 0, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 10, 'actual_weight_kg' => 100]),
    ]));
    $set = woExercise($payload, 'Bench Press')['sets'][0];

    // 100 kg is 25% above last week's 80 kg.
    expect($set['reps'])->toBeNull()
        ->and($set['weight']['value'])->toBe(100.0)
        ->and($set['off_table_text'])->toBe('1 RIR, just log reps');
});

it('OFF-022 a set logged at another weight changes no other set\'s target or rep table', function () {
    $planned = woExercise(woShow(), 'Bench Press')['sets'];
    $payload = woShow(...woStarted([
        woRow('slot-12', 0, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 10, 'actual_weight_kg' => 90, 'actual_reps' => 6, 'is_logged' => true]),
        woRow('slot-12', 1, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 9]),
    ]));
    $second = woExercise($payload, 'Bench Press')['sets'][1];

    expect($second['target_weight'])->toBe($planned[1]['target_weight'])
        ->and($second['target_text'])->toBe($planned[1]['target_text'])
        ->and($second['rep_table'])->toBe($planned[1]['rep_table'])
        ->and($second['id'])->toBe($planned[1]['id']);
});

it('WO-003 a logged set with no added load comes back with an empty weight, not its target', function () {
    // rp-clone test_WO_003_aLoggedSetWithNoAddedLoadComesBackWithAnEmptyWeight, in a week with a target load.
    $set = woExercise(woShow(...woStarted(
        [woRow('slot-12', 0, W::BENCH, ['target_weight_kg' => 10, 'target_reps' => 8, 'actual_weight_kg' => 0, 'actual_reps' => 8, 'is_logged' => true])],
        ['exercises' => [W::BENCH => ['load_type' => 'bodyweightPlusLoad', 'equipment' => 'bodyweight']]],
    )), 'Bench Press')['sets'][0];

    expect([$set['is_logged'], $set['weight'], $set['reps']])->toBe([true, null, 8])
        ->and($set['target_weight']['value'])->toBe(10.0);
});

it('WO-004 WO-020 an added set shows the load it holds with no target and no rep table', function () {
    $added = W::set('added-bench', 'm2w3d1', W::BENCH, 2, [
        'workout_exercise_id' => WorkoutIds::block(WorkoutIds::workout(W::MESO, 3, 1), 'slot-12'), 'actual_weight_kg' => 85, 'actual_reps' => 9,
    ]);
    $set = woExercise(woShow(...woStarted([
        woRow('slot-12', 0, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 10, 'actual_weight_kg' => 85, 'actual_reps' => 9, 'is_logged' => true]),
        $added,
    ])), 'Bench Press')['sets'][1];

    expect([$set['weight']['value'], $set['reps'], $set['is_logged']])->toBe([85.0, 9, false])
        ->and([$set['target_weight'], $set['target_text']])->toBe([null, null])
        ->and([$set['rep_table'], $set['off_table_text']])->toBe([[], null]);
});

// MARK: WO-006, PROG-026

it('WO-006 week 1 with no carried loads has empty weights and the RIR in the reps field', function () {
    $row = woExercise(woShow(week: 1, now: '2026-09-14 17:35'), 'Barbell Row');

    expect(woWeights($row))->toBe([null, null, null])
        ->and(woReps($row))->toBe([null, null, null])
        ->and(array_column($row['sets'], 'target_weight'))->toBe([null, null, null])
        ->and(array_column($row['sets'], 'target_text'))->toBe([null, null, null])
        ->and($row['sets'][0]['reps_placeholder'])->toBe('3 RIR')
        ->and($row['sets'][0]['rep_table'])->toBe([])
        ->and($row['sets'][0]['off_table_text'])->toBeNull()
        ->and($row['carries_first_weight'])->toBeTrue();
});

it('WO-006 PROG-026 a copied mesocycle fills week 1 from the old week 1, reps dropping 2 a set', function () {
    $bench = woExercise(woShow(week: 1, now: '2026-09-14 17:35'), 'Bench Press');

    // 80 kg from the old week 1, plus 5% is 84, which rounds to 85.
    expect(woWeights($bench))->toBe([85.0, 85.0, 85.0])
        ->and(woReps($bench))->toBe([10, 8, 6])
        ->and($bench['carries_first_weight'])->toBeFalse();
});

it('WO-006 a week with no history falls back to the week 1 rule', function () {
    // Nothing was logged for Lower A in week 1.
    $squat = woExercise(woShow(week: 2, day: 2, now: '2026-09-24 17:35'), 'Back Squat');

    expect(woWeights($squat))->toBe([null, null, null])
        ->and($squat['sets'][0]['reps_placeholder'])->toBe('2 RIR');
});

// MARK: WO-007

it('WO-007 the info icon on reps explains this week\'s RIR', function () {
    expect(woShow()['rir_info'])->toBe([
        'title' => 'Reps in reserve',
        'body' => 'This week\'s target is 1 RIR: stop each set with 1 rep left in the tank.',
        'article' => 'rir',
    ])
        ->and(woShow(week: 1, now: '2026-09-14 17:35')['rir_info']['body'])->toBe('This week\'s target is 3 RIR: stop each set with 3 reps left in the tank.');
});

it('WO-007 PROG-043 a deload week has no RIR target', function () {
    $payload = woShow(week: 5, now: '2026-10-12 17:35');

    expect($payload['rir_info']['body'])->toBe('This is a deload week. Keep every set easy; there is no RIR target.')
        ->and($payload['exercises'][0]['sets'][0]['reps_placeholder'])->toBe('Reps')
        ->and($payload['exercises'][0]['sets'][0]['rep_table'])->toBe([]);
});

// MARK: WO-008

it('WO-008 look back lists the earlier sessions of the mesocycle that were started or skipped, newest first', function () {
    $sessions = [...W::sessions(), W::session('m2w1d2', 1, 2, skipped: true), W::session('m2w1d1-moved', 1, 1)];

    expect(woShow(week: 5, now: '2026-10-12 17:35', sessions: $sessions)['look_back'])->toBe([
        ['week' => 4, 'day' => 2, 'title' => 'Week 4 · Day 2 · Lower A', 'status' => ['text' => 'Done', 'tone' => 'good']],
        ['week' => 4, 'day' => 1, 'title' => 'Week 4 · Day 1 · Upper A', 'status' => ['text' => 'Done', 'tone' => 'good']],
        ['week' => 2, 'day' => 1, 'title' => 'Week 2 · Day 1 · Upper A', 'status' => ['text' => 'Done', 'tone' => 'good']],
        ['week' => 1, 'day' => 2, 'title' => 'Week 1 · Day 2 · Lower A', 'status' => ['text' => 'Skipped', 'tone' => 'neutral']],
    ])
        ->and(array_column(woShow()['look_back'], 'title'))->toBe(['Week 2 · Day 1 · Upper A']);
});

it('WO-008 an earlier session opens read only', function () {
    $payload = woShow(week: 2, day: 1);

    expect($payload['status'])->toBe('finished')
        ->and($payload['is_editable'])->toBeFalse()
        ->and(woWeights(woExercise($payload, 'Bench Press')))->toBe([80.0, 80.0, 80.0])
        ->and(woReps(woExercise($payload, 'Bench Press')))->toBe([10, 9, 8])
        ->and($payload['warm_up'])->toBeNull()
        ->and($payload['can_finish'])->toBeFalse();
});

// MARK: WO-009

it('WO-009 the footer names the first meal after the session ends', function () {
    // The session runs 17:30 for 75 minutes; the third meal is at 19:30.
    expect(woShow()['next_meal'])->toBe('Next: Meal 3 at 7:30 PM · 95 g carbs');
});

it('WO-009 a session running late uses now instead of the planned end', function () {
    expect(woShow(now: '2026-09-28 19:00')['next_meal'])->toBe('Next: Meal 3 at 7:30 PM · 95 g carbs')
        ->and(woShow(now: '2026-09-28 20:00')['next_meal'])->toBeNull();
});

it('WO-009 a session moved to the morning names the first meal at or after its end', function () {
    $sessions = [...W::sessions(), W::session('m2w3d1', 3, 1, attributes: ['planned_start_at' => '2026-09-28 07:00:00', 'planned_end_at' => '2026-09-28 07:30:00'])];

    expect(woShow(now: '2026-09-28 07:00', sessions: $sessions)['next_meal'])->toBe('Next: Breakfast at 8:00 AM · 100 g carbs');
});

it('WO-009 the footer is hidden without a day target', function () {
    expect(woShow(dayTargets: [])['next_meal'])->toBeNull();
});

// MARK: WO-020 to WO-024

it('WO-020 WO-021 the set menu\'s types are Regular in three variants, Myorep and Myorep match, each myorep with its guidance', function () {
    expect(woShow()['set_type_choices'])->toBe([
        ['value' => 'regular_straight', 'label' => 'Regular · Straight', 'note' => null],
        ['value' => 'regular_down', 'label' => 'Regular · Down', 'note' => null],
        ['value' => 'regular_ascending', 'label' => 'Regular · Ascending', 'note' => null],
        ['value' => 'myorep', 'label' => 'Myorep (M)', 'note' => 'Rest 5 to 15 seconds between mini-sets, and log the total reps.'],
        ['value' => 'myorep_match', 'label' => 'Myorep match (MM)', 'note' => 'Do as many mini-sets as you need to match the first set\'s reps.'],
    ]);
});

it('WO-022 the exercise menu adds a regular, myorep or myorep match set', function () {
    expect(array_column(woShow()['add_set_choices'], 'value'))->toBe(['regular_straight', 'myorep', 'myorep_match'])
        ->and(array_column(woShow()['add_set_choices'], 'label'))->toBe(['Regular', 'Myorep (M)', 'Myorep match (MM)']);
});

it('WO-021 WO-022 a set shows its stored type, with M or MM on a myorep, and the session note on its exercise', function () {
    $workout = WorkoutIds::workout(W::MESO, 3, 1);
    $world = woStarted([
        woRow('slot-12', 0, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 10, 'variant' => 'down']),
        woRow('slot-12', 1, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 9, 'type' => 'myorep', 'variant' => null]),
        woRow('slot-12', 2, W::BENCH, ['target_weight_kg' => 82.5, 'target_reps' => 8, 'type' => 'myorep_match', 'variant' => null]),
    ]);
    $world['blocks'][0] = W::block(WorkoutIds::block($workout, 'slot-12'), 'm2w3d1', W::BENCH, 1, 'Superset with face pulls');
    $bench = woExercise(woShow(...$world), 'Bench Press');

    expect(array_column($bench['sets'], 'set_type'))->toBe(['regular_down', 'myorep', 'myorep_match'])
        ->and(array_column($bench['sets'], 'badge'))->toBe([null, 'M', 'MM'])
        ->and($bench['session_note'])->toBe('Superset with face pulls');
});

it('WO-023 a dumbbell exercise asks for one dumbbell\'s weight, and the help explains one side at a time', function () {
    $payload = woShow();
    $help = array_column($payload['help'], 'body', 'title');

    expect(woExercise($payload, 'Dumbbell Curl')['weight_label'])->toBe('Weight of one dumbbell (kg)')
        ->and(woExercise($payload, 'Bench Press')['weight_label'])->toBe('Weight (kg)')
        ->and($help['Dumbbells and one side at a time'])->toContain('weaker side')
        ->and($help['Dumbbells and one side at a time'])->toContain('one side\'s reps');
});

it('WO-024 the warm-up card shows on the first exercise until a set is done, and is never logged', function () {
    $payload = woShow();

    expect($payload['warm_up'])->toBe([
        'workout_exercise_id' => $payload['exercises'][0]['id'],
        'title' => 'Warm up first',
        'steps' => [
            ['text' => '12 reps at a weight you could lift 30 or more times'],
            ['text' => '8 reps at a weight you could lift about 20 times'],
            ['text' => '4 reps at about your 10-rep max'],
            ['text' => 'Then your working sets, at 3 RIR'],
        ],
        'note' => 'Warm-up sets are not logged.',
    ])
        ->and(woShow(...woStarted([woRow('slot-12', 0, W::BENCH, ['actual_weight_kg' => 80, 'actual_reps' => 10, 'is_logged' => true])]))['warm_up'])->toBeNull();
});

// MARK: PROG-041 to PROG-043

it('PROG-041 the first half of a deload week halves sets and reps at the same weight', function () {
    $payload = woShow(week: 5, now: '2026-10-12 17:35');
    $bench = woExercise($payload, 'Bench Press');
    $curl = woExercise($payload, 'Dumbbell Curl');

    expect(woWeights($bench))->toBe([90.0])
        ->and(woReps($bench))->toBe([4])
        ->and(woWeights($curl))->toBe([14.0])
        ->and(woReps($curl))->toBe([6])
        // Barbell Row was not logged in week 4: no weight or reps to halve.
        ->and(woWeights(woExercise($payload, 'Barbell Row')))->toBe([null]);
});

it('PROG-041 PROG-042 the second half also halves the weight, and traps are left out', function () {
    $payload = woShow(week: 5, day: 2, now: '2026-10-15 17:35');

    expect(array_column($payload['exercises'], 'name'))->toBe(['Back Squat'])
        ->and(woWeights($payload['exercises'][0]))->toBe([50.0])
        ->and(woReps($payload['exercises'][0]))->toBe([5])
        ->and(array_column(woShow(day: 2, now: '2026-10-01 17:35')['exercises'], 'name'))->toBe(['Back Squat', 'Barbell Shrug']);
});

it('PROG-041 the halved weight is rounded to the load step', function () {
    $sets = array_values(array_filter(W::sets(), fn ($set) => ! str_starts_with($set->id, 'w4-squat')));
    $sets[] = W::logged('w4-squat-odd', 'm2w4d2', W::SQUAT, 1, 81.25, 10);

    expect(woWeights(woShow(week: 5, day: 2, now: '2026-10-15 17:35', sets: $sets)['exercises'][0]))->toBe([40.0]);
});

it('PROG-043 a deload week shows the banner saying it is meant to feel easy', function () {
    $banner = woShow(week: 5, now: '2026-10-12 17:35')['deload_banner'];

    expect($banner['text'])->toBe('The deload is meant to feel easy. Push the 0 RIR week as hard as possible before it.')
        ->and(woShow()['deload_banner'])->toBeNull();
});

it('WO-030 WO-052 the help explains resting by feel, sickness, sport and long workouts', function () {
    expect(array_column(woShow()['help'], 'title'))->toBe([
        'Resting by feel', 'Warm-ups', 'Dumbbells and one side at a time', 'Deload week', 'Training while sick',
        'Training alongside a sport', 'Long workouts',
    ])
        ->and(woShow()['swap_advice'])->toContain('only for a good reason');
});

// MARK: OFF-020, feedback (FB-001 to FB-009), WO-040, NAV-004

it('OFF-020 the screen carries everything the session needs offline: targets, rep tables, feedback, notes and the next meal', function () {
    $payload = woShow();

    expect($payload['next_meal'])->not->toBeNull()
        ->and(array_column($payload['feedback'], 'muscle_group'))->toBe(['chest', 'back', 'biceps'])
        ->and($payload['feedback'][0]['due_after_exercise_id'])->toBe($payload['exercises'][0]['id'])
        ->and(array_column($payload['feedback'][0]['questions'], 'kind'))->toBe(['joint', 'soreness', 'pump', 'volume']);
    foreach ($payload['exercises'] as $exercise) {
        foreach ($exercise['sets'] as $set) {
            expect($set['rep_table'])->not->toBe([])->and($set['target_weight'])->not->toBeNull();
        }
    }
});

it('OFF-020 FB-001 FB-002 a muscle\'s sheet falls due once its last exercise is done, asking joints per exercise', function () {
    $payload = woShow(...woStarted([
        woRow('slot-12', 0, W::BENCH, ['actual_weight_kg' => 82.5, 'actual_reps' => 10, 'is_logged' => true]),
        woRow('slot-12', 1, W::BENCH, ['is_skipped' => true]),
        woRow('slot-25', 0, W::ROW, ['target_weight_kg' => 62.5, 'target_reps' => 7]),
    ], ['muscleFeedback' => [W::muscleFeedback('m2w3d1', 'chest', null, 'low', null)], 'exerciseFeedback' => [W::jointPain('m2w3d1', W::BENCH, 'low')]]));
    $chest = $payload['feedback'][0];

    expect(array_column($payload['feedback'], 'muscle_group'))->toBe(['chest', 'back'])
        ->and([$chest['is_due'], $chest['is_answered'], $chest['can_edit']])->toBe([true, false, true])
        ->and($chest['questions'][0])->toMatchArray(['kind' => 'joint', 'exercise_id' => (string) W::BENCH, 'text' => 'How did your joints feel during Bench Press?', 'answer' => 'low'])
        ->and(array_column($chest['questions'], 'answer'))->toBe(['low', null, 'low', null])
        ->and($payload['feedback'][1]['is_due'])->toBeFalse()
        ->and($payload['feedback_banner'])->toBe(['text' => 'Your feedback is required', 'tone' => 'warning'])
        ->and($payload['can_finish'])->toBeFalse()
        ->and($payload['active_bar_text'])->toBe('Upper A · 2 of 3 exercises');
});

it('FB-007 soreness is not asked the first time a muscle trains here, nor after more than 14 days', function () {
    // Week 2's bench was a week earlier: asked. Curl first trained in week 2 too. Squat on Thursday 1 Oct: never before.
    $chest = woShow()['feedback'][0];
    $lower = woShow(day: 2, now: '2026-10-01 17:35')['feedback'][0];
    $late = woShow(week: 4, now: '2026-10-05 17:35', sessions: [W::session('m2w2d1', 2, 1, '2026-09-14 17:30:00', '2026-09-14 18:30:00')])['feedback'][0];

    expect(array_column($chest['questions'], 'kind'))->toContain('soreness')
        ->and(array_column($lower['questions'], 'kind'))->toBe(['joint', 'pump', 'volume'])
        ->and(array_column($late['questions'], 'kind'))->not->toContain('soreness');
});

it('FB-008 no feedback is asked in a deload week', function () {
    expect(woShow(week: 5, now: '2026-10-12 17:35')['feedback'])->toBe([]);
});

it('WO-040 finish is available once every set is logged or skipped and the due feedback is answered', function () {
    $sets = [
        woRow('slot-12', 0, W::BENCH, ['actual_weight_kg' => 82.5, 'actual_reps' => 10, 'is_logged' => true]),
        woRow('slot-25', 0, W::ROW, ['is_skipped' => true]),
        woRow('slot-40', 0, W::CURL, ['actual_weight_kg' => 14, 'actual_reps' => 12, 'is_logged' => true]),
    ];
    $answers = [
        'muscleFeedback' => array_map(fn (string $muscle) => W::muscleFeedback('m2w3d1', $muscle, 'healedJustOnTime', 'moderate', 'justRight'), ['chest', 'back', 'biceps']),
        'exerciseFeedback' => array_map(fn (int $exercise) => W::jointPain('m2w3d1', $exercise, 'none'), [W::BENCH, W::ROW, W::CURL]),
    ];

    $unanswered = woShow(...woStarted($sets));
    $answered = woShow(...woStarted($sets, $answers));

    expect([$unanswered['can_finish'], $unanswered['feedback_banner']['text'] ?? null])->toBe([false, 'Your feedback is required'])
        ->and([$answered['can_finish'], $answered['feedback_banner']])->toBe([true, null])
        ->and(array_column($answered['feedback'], 'is_answered'))->toBe([true, true, true])
        ->and($answered['active_bar_text'])->toBe('Upper A · 3 of 3 exercises');
});

it('FB-009 a sheet can no longer be changed once a later session has trained the muscle', function () {
    $later = W::session('m2w3d2', 3, 2, '2026-10-01 17:30:00');
    $world = woStarted(
        [
            woRow('slot-12', 0, W::BENCH, ['actual_weight_kg' => 82.5, 'actual_reps' => 10, 'is_logged' => true]),
            woRow('slot-25', 0, W::ROW, ['target_weight_kg' => 62.5, 'target_reps' => 7]),
            woRow('slot-40', 0, W::CURL, ['target_weight_kg' => 14, 'target_reps' => 13]),
        ],
        ['history' => [W::sessions()[0], $later], 'historySets' => [...W::sets(), W::logged('later-bench', 'm2w3d2', W::BENCH, 1, 80, 10)]],
    );

    expect(array_column(woShow(...$world)['feedback'], 'can_edit', 'muscle_group'))->toBe(['chest' => false, 'back' => true, 'biceps' => true]);
});
