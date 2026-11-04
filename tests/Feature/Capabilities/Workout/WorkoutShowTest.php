<?php

use App\Actions\Mesocycles\SetMusclePriority;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Workout\WorkoutIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\ScreenFixture;
use Tests\Support\TrainSeed;

// The HTTP seam of workout_show and its screen fixtures. The rules are unit tests (WorkoutPayloadTest). Meso 2 runs
// 5 weeks from Monday 7 September: Monday 21 September is week 3, day 1 "Upper A" (Bench Press, 3 sets).

afterEach(fn () => Carbon::setTestNow());

/** The user, with barbell Bench Press and Barbell Row on Upper A and week 2's bench logged. */
function showSeed(): array
{
    Carbon::setTestNow('2026-09-21 17:00:00');
    $bench = Exercise::query()->where('name', 'Bench Press')->first() ?? Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => 5, 'rep_range_max' => 10,
    ]);
    $row = Exercise::factory()->create([
        'name' => 'Barbell Row', 'muscle_group' => MuscleGroup::Back, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => 5, 'rep_range_max' => 10,
    ]);
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    $days = $mesocycle->days;
    $days[0]['slots'][] = ['id' => '01JB6Q402A2B3C4D5E6F7G8H9J', 'exercise_id' => $row->id, 'muscle_group' => 'back', 'sets' => 3];
    $mesocycle->update(['days' => $days]);
    TrainSeed::trained($mesocycle, 2, 1, '2026-09-14');
    DayTarget::factory()->for($user)->create(['date' => '2026-09-21', 'meals' => [
        ['id' => '01JB6Q500A2B3C4D5E6F7G8H9J', 'name' => 'Breakfast', 'time' => '08:00', 'kcal' => 600, 'protein_g' => 40, 'fat_g' => 20, 'carbs_g' => 70],
        ['id' => '01JB6Q501A2B3C4D5E6F7G8H9J', 'name' => null, 'time' => '19:00', 'kcal' => 900, 'protein_g' => 60, 'fat_g' => 25, 'carbs_g' => 95],
    ]]);

    return [$user, $mesocycle->fresh(), $bench, $row];
}

function showOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/workout_show', $input);
}

it('WO-001 refuses workout_show when signed out', function () {
    showOver(null, ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5', 'week' => 1, 'day' => 1])->assertUnauthorized();
});

it('WO-001 answers 404 for another user\'s mesocycle', function () {
    $theirs = TrainSeed::mesocycle(User::factory()->create());

    showOver(TrainSeed::user(), ['mesocycle_id' => $theirs->id, 'week' => 1, 'day' => 1])->assertNotFound();
});

it('WO-001 answers 404 for the caller\'s own draft and for a day the mesocycle does not have', function () {
    $user = TrainSeed::user();
    $draft = TrainSeed::mesocycle($user, ['is_draft' => true]);
    $saved = TrainSeed::mesocycle($user);

    showOver($user, ['mesocycle_id' => $draft->id, 'week' => 1, 'day' => 1])->assertNotFound();
    showOver($user, ['mesocycle_id' => $saved->id, 'week' => 1, 'day' => 3])->assertNotFound();
    showOver($user, ['mesocycle_id' => $saved->id, 'week' => 6, 'day' => 1])->assertNotFound();
});

it('WO-001 answers 422 naming the field when the week is below 1', function () {
    $user = TrainSeed::user();

    showOver($user, ['mesocycle_id' => TrainSeed::mesocycle($user)->id, 'week' => 0, 'day' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'week');
});

it('WO-001 WO-004 OFF-020 shows a session not started yet, with its targets, and writes nothing', function () {
    [$user, $mesocycle] = showSeed();
    $before = [WorkoutSession::query()->count(), WorkoutSet::query()->count(), WorkoutExercise::query()->count()];

    $data = showOver($user, ['mesocycle_id' => strtoupper($mesocycle->id), 'week' => 3, 'day' => 1])->assertOk()->json('data');

    assertMatchesScreenFixture('workout_show', $data);
    expect($data['workout_id'])->toBe(WorkoutIds::workout($mesocycle->id, 3, 1))
        ->and($data['title'])->toBe('Week 3 · Day 1 · Upper A · Mon 21 Sep')
        ->and($data['status'])->toBe('not_started')
        ->and(array_column($data['exercises'], 'name'))->toBe(['Bench Press', 'Barbell Row'])
        // 81 kg for 10 on last week's first set; 2% more rounds to 82.5 kg on a 2.5 kg step (PROG-021).
        ->and($data['exercises'][0]['sets'][0]['weight']['text'])->toBe('82.5 kg')
        ->and($data['next_meal'])->toBe('Next: Meal 2 at 7:00 PM · 95 g carbs')
        ->and(array_column($data['look_back'], 'title'))->toBe(['Week 2 · Day 1 · Upper A'])
        ->and([WorkoutSession::query()->count(), WorkoutSet::query()->count(), WorkoutExercise::query()->count()])->toBe($before);
});

it('WO-006 shows week 1 of a day with no exercise chosen yet as an empty session', function () {
    Carbon::setTestNow('2026-09-07 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user, ['days' => [
        ['id' => '01JB6Q400A2B3C4D5E6F7G8H9J', 'label' => 'Upper A', 'weekday' => 1, 'slots' => [
            ['id' => '01JB6Q401A2B3C4D5E6F7G8H9J', 'exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 3],
        ]],
    ]]);

    $data = showOver($user, ['mesocycle_id' => $mesocycle->id, 'week' => 1, 'day' => 1])->assertOk()->json('data');

    assertMatchesScreenFixture('workout_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('workout_show.empty'), $data))->toBe([])
        ->and($data['exercises'])->toBe([])
        ->and($data['warm_up'])->toBeNull()
        ->and($data['rir_info']['body'])->toBe('This week\'s target is 3 RIR: stop each set with 3 reps left in the tank.');
});

it('WO-040 NAV-004 shows a session under way whose due feedback blocks Finish', function () {
    [$user, $mesocycle, $bench, $row] = showSeed();
    $workout = WorkoutIds::workout($mesocycle->id, 3, 1);
    $session = WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1, 'actual_start_at' => '2026-09-21 17:05:00',
    ]);
    foreach ([[$bench, '01JB6Q401A2B3C4D5E6F7G8H9J'], [$row, '01JB6Q402A2B3C4D5E6F7G8H9J']] as $position => [$exercise, $slot]) {
        $block = WorkoutExercise::factory()->create([
            'id' => WorkoutIds::block($workout, $slot), 'workout_session_id' => $session->id,
            'exercise_id' => $exercise->id, 'position' => $position + 1, 'note' => $position === 1 ? 'Superset with face pulls' : null,
        ]);
        foreach ([0, 1] as $index) {
            WorkoutSet::factory()->create([
                'id' => WorkoutIds::set($workout, $slot, $index), 'workout_session_id' => $session->id,
                'workout_exercise_id' => $block->id, 'exercise_id' => $exercise->id, 'position' => $index + 1,
                'target_weight_kg' => 85, 'target_reps' => 10 - $index,
                ...($position === 0 ? ['actual_weight_kg' => 85, 'actual_reps' => 10 - $index, 'is_logged' => true] : []),
            ]);
        }
    }
    MuscleFeedback::factory()->create(['workout_session_id' => $session->id, 'muscle_group' => 'chest', 'soreness' => 'healedJustOnTime', 'pump' => null, 'volume' => null]);
    ExerciseFeedback::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $bench->id, 'joint_pain' => 'low']);

    $data = showOver($user, ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->assertOk()->json('data');

    assertMatchesScreenFixture('workout_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('workout_show.warning'), $data))->toBe([])
        ->and($data['status'])->toBe('in_progress')
        ->and($data['active_bar_text'])->toBe('Upper A · 1 of 2 exercises')
        ->and($data['feedback_banner'])->toBe(['text' => 'Your feedback is required', 'tone' => 'warning'])
        ->and($data['exercises'][1]['session_note'])->toBe('Superset with face pulls')
        ->and($data['feedback'][0]['questions'][0]['answer'])->toBe('low')
        ->and($data['can_finish'])->toBeFalse();
});

it('WO-011 WO-008 shows a finished session with its recap', function () {
    [$user, $mesocycle] = showSeed();

    // Week 2 day 1 ran 17:30 to 18:30 on 14 September: Bench Press 81 kg and 82 kg for 10.
    $data = showOver($user, ['mesocycle_id' => $mesocycle->id, 'week' => 2, 'day' => 1])->assertOk()->json('data');

    assertMatchesScreenFixture('workout_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('workout_show.finished'), $data))->toBe([])
        ->and($data['status'])->toBe('finished')
        ->and($data['recap']['stats'])->toBe([
            ['label' => 'Time', 'value' => '1 h'],
            ['label' => 'Sets', 'value' => '2'],
            ['label' => 'Lifted', 'value' => '1,630 kg'],
        ])
        ->and($data['recap']['exercises'][0]['best_text'])->toBe('82 kg × 10');
});

it('WO-001 never shows another user\'s session rows', function () {
    [$user, $mesocycle] = showSeed();
    $stranger = User::factory()->create();
    WorkoutSession::factory()->for($stranger)->create(['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1, 'actual_start_at' => '2026-09-21 17:00:00']);

    expect(showOver($user, ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->json('data.status'))->toBe('not_started')
        ->and(Mesocycle::query()->count())->toBe(1);
});

it('WO-002 WO-009 FB-007 FB-009 another user\'s notes, load steps, day target and sessions never reach the caller\'s screen', function () {
    // The stranger's rows come first, so a lookup that lost its user scope would find theirs.
    Carbon::setTestNow('2026-09-21 17:00:00');
    $bench = Exercise::factory()->create([
        'name' => 'Bench Press', 'muscle_group' => MuscleGroup::Chest, 'equipment' => Equipment::Barbell,
        'load_type' => ExerciseLoadType::Barbell, 'rep_range_min' => 5, 'rep_range_max' => 10,
    ]);
    $stranger = User::factory()->create();
    ExerciseNote::factory()->for($stranger)->create(['exercise_id' => $bench->id, 'note' => 'Their grip cue']);
    ExerciseLoadStep::factory()->for($stranger)->create(['exercise_id' => $bench->id, 'step_kg' => 5]);
    DayTarget::factory()->for($stranger)->create(['date' => '2026-09-21', 'meals' => [
        ['id' => '01JB6Q600A2B3C4D5E6F7G8H9J', 'name' => 'Shake', 'time' => '18:45', 'kcal' => 300, 'protein_g' => 40, 'fat_g' => 5, 'carbs_g' => 30],
    ]]);
    [$user, $mesocycle, , $row] = showSeed();
    test()->actingAs($user)->postJson('/capabilities/workout_start', ['workout_id' => WorkoutIds::workout($mesocycle->id, 3, 1)], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
    // Their back trained two days ago (FB-007 would ask soreness) and again after the caller started (FB-009 would lock it).
    foreach (['2026-09-19 10:00:00', '2026-09-21 17:30:00'] as $at) {
        $theirs = WorkoutSession::factory()->for($stranger)->create(['actual_start_at' => $at]);
        WorkoutSet::factory()->logged()->create(['workout_session_id' => $theirs->id, 'exercise_id' => $row->id]);
    }

    $data = showOver($user, ['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1])->assertOk()->json('data');
    $back = array_values(array_filter($data['feedback'], fn (array $sheet) => $sheet['muscle_group'] === 'back'))[0];

    expect($data['exercises'][0]['pinned_note'])->toBeNull()
        // The default 2.5 kg step: 82.5 kg planned, and a table from 72.5 kg (a 5 kg step would start at 75).
        ->and($data['exercises'][0]['sets'][0]['target_weight']['value'])->toEqual(82.5)
        ->and($data['exercises'][0]['sets'][0]['rep_table'][0]['weight']['value'])->toEqual(72.5)
        ->and($data['next_meal'])->toBe('Next: Meal 2 at 7:00 PM · 95 g carbs')
        ->and(array_column($back['questions'], 'kind'))->toBe(['joint', 'pump', 'volume'])
        ->and($back['can_edit'])->toBeTrue();
});

it('MESO-034 WO-001 a muscle set Off is left out of a session not started yet', function () {
    [$user, $mesocycle] = showSeed();
    app(SetMusclePriority::class)($user, $mesocycle->id, MuscleGroup::Chest, null);

    // Week 4 day 1, Upper A on Monday 28 September, has not started: its bench (chest) is left out, its row stays.
    $data = showOver($user, ['mesocycle_id' => $mesocycle->id, 'week' => 4, 'day' => 1])->assertOk()->json('data');

    expect($data['status'])->toBe('not_started')
        ->and(array_column($data['exercises'], 'name'))->toBe(['Barbell Row']);
});

it('MESO-034 a week that ran before a muscle went Off still shows its exercises', function () {
    [$user, $mesocycle] = showSeed();
    app(SetMusclePriority::class)($user, $mesocycle->id, MuscleGroup::Chest, null);

    // Week 1 ran before chest went Off; it is stored in earlier_weeks as it ran, bench included.
    expect($mesocycle->fresh()->earlierWeekRows())->toHaveKey(0);
    $data = showOver($user, ['mesocycle_id' => $mesocycle->id, 'week' => 1, 'day' => 1])->assertOk()->json('data');

    expect(array_column($data['exercises'], 'name'))->toBe(['Bench Press', 'Barbell Row']);
});
