<?php

use App\Capabilities\Reads\Progress\ExerciseHistoryPayload;
use App\Capabilities\Support\Ownership;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Progress\ExerciseHistorySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProgressSeed;
use Tests\Support\ScreenFixture;

// The HTTP seam of exercise_history_show and its screen fixtures. The rules are unit tests
// (ExerciseHistoryPayloadTest, EstimatedOneRepMaxTest).

beforeEach(fn () => Carbon::setTestNow(ProgressSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

function historyOf($test, User $user, Exercise|string $exercise, ?string $before = null)
{
    $input = ['exercise_id' => $exercise instanceof Exercise ? (string) $exercise->id : $exercise];
    if (func_num_args() > 3) {
        $input['before'] = $before;
    }

    return $test->actingAs($user)->postJson('/capabilities/exercise_history_show', $input);
}

/**
 * Sessions of one exercise for the user, each with two logged sets whose weights differ by session, one a day back
 * from 2026-10-09, or all at $at when given (null for undated).
 *
 * @return list<WorkoutSession> newest first, as the read orders them
 */
function benchSessions(User $user, Exercise $exercise, int $count, string|false|null $at = false): array
{
    $sessions = [];
    foreach (range(1, $count) as $day) {
        $session = WorkoutSession::factory()->for($user)->create([
            'actual_start_at' => $at === false ? date('Y-m-d 09:00:00', strtotime("2026-10-10 -{$day} days")) : $at,
            'planned_start_at' => null,
        ]);
        foreach ([[40 + $day, 8], [41 + $day, 6 + $day % 4]] as $position => [$kg, $reps]) {
            WorkoutSet::factory()->create([
                'workout_session_id' => $session->id, 'exercise_id' => $exercise->id, 'position' => $position + 1,
                'actual_weight_kg' => $kg, 'actual_reps' => $reps, 'is_logged' => true,
            ]);
        }
        $sessions[] = $session;
    }

    return $sessions;
}

/** Every page of the user's history of the exercise, read through the cursor. @return list<array<string, mixed>> */
function everyPage($test, User $user, Exercise $exercise): array
{
    $pages = [historyOf($test, $user, $exercise)->assertOk()->json('data')];
    while (end($pages)['next_before'] !== null && count($pages) < 10) {
        $pages[] = historyOf($test, $user, $exercise, end($pages)['next_before'])->assertOk()->json('data');
    }

    return $pages;
}

it('ANA-009 refuses exercise_history_show when signed out', function () {
    $this->postJson('/capabilities/exercise_history_show', ['exercise_id' => '1'])->assertUnauthorized();
});

it('ANA-009 answers 404 for another user\'s custom exercise, an unknown one and one that is not a number', function () {
    $user = User::factory()->create();
    $theirs = Exercise::factory()->custom()->create();

    historyOf($this, $user, $theirs)->assertNotFound();
    historyOf($this, $user, '999999')->assertNotFound();
    historyOf($this, $user, 'abc')->assertNotFound();
});

it('ANA-009 answers 404 for an id with trailing or leading characters, though the number is an exercise the user can see', function () {
    $user = User::factory()->create();
    $visible = Exercise::factory()->create();

    historyOf($this, $user, $visible)->assertOk();
    historyOf($this, $user, "{$visible->id}abc")->assertNotFound();
    historyOf($this, $user, " {$visible->id}")->assertNotFound();
});

it('EX-002 shows a custom exercise\'s history to its owner only', function () {
    ['user' => $user] = ProgressSeed::world();
    $mesocycle = ProgressSeed::mesocycle($user, 'Own', Phase::query()->where('user_id', $user->id)->value('id'), '2026-10-01', 2);
    $custom = Exercise::factory()->custom($user)->create(['name' => 'My press', 'muscle_group' => MuscleGroup::Chest]);
    $session = ProgressSeed::session($user, $mesocycle, 1, '2026-10-09 09:00:00', [[$custom, 50, 10]]);

    $data = historyOf($this, $user, $custom)->assertOk()->json('data');
    historyOf($this, ProgressSeed::stranger(), $custom)->assertNotFound();

    expect($data['title'])->toBe('My press')
        ->and(array_column($data['sessions'], 'id'))->toBe([$session->id]);
});

it('EX-002 lists a session\'s sets by position, not by when they were logged', function () {
    ['user' => $user, 'bench' => $bench, 'benchSessions' => [$first]] = ProgressSeed::world();
    // Added after the others, at the top: an inserted warm-up.
    WorkoutSet::factory()->create([
        'workout_session_id' => $first->id, 'exercise_id' => $bench->id, 'position' => 0,
        'actual_weight_kg' => 40, 'actual_reps' => 12, 'is_logged' => true,
    ]);

    $labels = array_column(historyOf($this, $user, $bench)->assertOk()->json('data.sessions.1.sets'), 'label');

    expect($labels)->toBe(['40.0 kg × 12', '60.0 kg × 9', '62.5 kg × 8']);
});

it('ANA-009 answers 422 naming exercise_id when it is missing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/exercise_history_show', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'exercise_id');
});

it('ANA-009 EX-002 shows a seeded exercise in the fixture\'s shape, newest session first', function () {
    ['user' => $user, 'bench' => $bench, 'benchSessions' => [$first, $second]] = ProgressSeed::world();

    $data = historyOf($this, $user, $bench)->assertOk()->json('data');

    assertMatchesScreenFixture('exercise_history_show', $data);
    expect($data['title'])->toBe('Bench Press')
        ->and($data['subtitle'])->toBe('Chest')
        ->and(array_column($data['sessions'], 'id'))->toBe([$second->id, $first->id])
        ->and(array_column($data['sessions'], 'date_label'))->toBe(['Thu, 8 Oct 2026', 'Mon, 5 Oct 2026'])
        ->and(array_column($data['sessions'], 'best_label'))->toBe(['Best e1RM 82.3 kg', 'Best e1RM 79.2 kg'])
        ->and(array_column($data['sessions'][0]['sets'], 'label'))->toBe(['65.0 kg × 8', '65.0 kg × 7', '62.5 kg × 8'])
        ->and(array_column($data['sessions'][0]['sets'], 'is_best'))->toBe([true, false, false]);
});

it('ANA-009 shows an exercise never trained in the empty fixture\'s shape', function () {
    ['user' => $user] = ProgressSeed::world();

    $data = historyOf($this, $user, Exercise::factory()->create(['name' => 'Squat']))->assertOk()->json('data');

    assertMatchesScreenFixture('exercise_history_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('exercise_history_show.empty'), $data))->toBe([])
        ->and($data['sessions'])->toBe([]);
});

it('ANA-009 shows the user\'s weights in pounds when that is the unit', function () {
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();
    Profile::query()->where('user_id', $user->id)->update(['weight_unit' => 'lb']);

    $data = historyOf($this, $user, $bench)->assertOk()->json('data');

    expect($data['sessions'][0]['sets'][0]['label'])->toBe('143.3 lb × 8');
});

it('EX-002 leaves out unlogged and deleted sets, deleted sessions, other exercises and other users', function () {
    ['user' => $user, 'bench' => $bench, 'row' => $row] = ProgressSeed::world();
    $session = WorkoutSession::factory()->for($user)->create(['actual_start_at' => '2026-10-09 18:00:00']);
    $logged = ['workout_session_id' => $session->id, 'exercise_id' => $bench->id, 'is_logged' => true, 'actual_weight_kg' => 100, 'actual_reps' => 10];
    $kept = WorkoutSet::factory()->create([...$logged, 'position' => 1]);
    WorkoutSet::factory()->create([...$logged, 'position' => 2, 'is_logged' => false]);
    WorkoutSet::factory()->create([...$logged, 'position' => 3])->delete();
    WorkoutSet::factory()->create([...$logged, 'position' => 4, 'exercise_id' => $row->id]);
    $deleted = WorkoutSession::factory()->for($user)->create(['actual_start_at' => '2026-10-09 19:00:00']);
    WorkoutSet::factory()->create([...$logged, 'workout_session_id' => $deleted->id]);
    $deleted->delete();
    ProgressSeed::stranger();

    $data = historyOf($this, $user, $bench)->assertOk()->json('data');

    expect($data['sessions'])->toHaveCount(3)
        ->and($data['sessions'][0]['id'])->toBe($session->id)
        ->and(array_column($data['sessions'][0]['sets'], 'id'))->toBe([$kept->id])
        ->and(collect($data['sessions'])->pluck('id')->all())->not->toContain($deleted->id);
});

it('EX-002 shows each user only their own sets of a shared exercise', function () {
    $stranger = ProgressSeed::stranger();
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();

    $mine = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');
    $theirs = historyOf($this, $stranger, $bench)->assertOk()->json('data.sessions');

    // The stranger (lb) is made first, so the viewer's kg labels prove the unit is read from the viewer's profile.
    expect($mine)->toHaveCount(2)
        ->and($mine[0]['sets'][0]['label'])->toEndWith(' kg × 8')
        ->and($theirs)->toHaveCount(1)
        ->and($theirs[0]['sets'][0]['label'])->toBe('220.5 lb × 10');
});

it('EX-002 leaves out a set that names the viewer but sits in another user\'s session', function () {
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();
    $before = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');
    $theirs = WorkoutSession::factory()->for(User::factory()->create())->create(['actual_start_at' => '2026-10-09 18:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $theirs->id, 'user_id' => $user->id, 'exercise_id' => $bench->id, 'is_logged' => true, 'actual_weight_kg' => 100, 'actual_reps' => 10]);

    $after = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');

    expect($after)->toBe($before)
        ->and(array_column($after, 'id'))->not->toContain($theirs->id);
});

it('EX-002 leaves out a set in the viewer\'s session that names another user', function () {
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();
    $before = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');
    $session = WorkoutSession::factory()->for($user)->create(['actual_start_at' => '2026-10-09 18:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $session->id, 'user_id' => User::factory()->create()->id, 'exercise_id' => $bench->id, 'is_logged' => true, 'actual_weight_kg' => 100, 'actual_reps' => 10]);

    $after = historyOf($this, $user, $bench)->assertOk()->json('data.sessions');

    expect($after)->toBe($before)
        ->and(array_column($after, 'id'))->not->toContain($session->id);
});

it('ANA-009 EX-002 reads the newest 20 sessions first, with the id of the 20th to read older ones', function () {
    ['user' => $user] = ProgressSeed::world();
    $bench = Exercise::factory()->create(['name' => 'Squat', 'muscle_group' => MuscleGroup::Quads]);
    $sessions = benchSessions($user, $bench, 45);

    $data = historyOf($this, $user, $bench)->assertOk()->json('data');

    assertMatchesScreenFixture('exercise_history_show', $data);
    expect(array_column($data['sessions'], 'id'))->toBe(array_column(array_slice($sessions, 0, 20), 'id'))
        ->and($data['next_before'])->toBe($sessions[19]->id);
});

it('EX-002 reaches every session through the cursor, newest first, with no repeats', function () {
    ['user' => $user] = ProgressSeed::world();
    $bench = Exercise::factory()->create(['name' => 'Squat']);
    $ids = array_column(benchSessions($user, $bench, 45), 'id');

    $second = historyOf($this, $user, $bench, $ids[19])->assertOk()->json('data');
    $third = historyOf($this, $user, $bench, $second['next_before'])->assertOk()->json('data');

    expect(array_column($second['sessions'], 'id'))->toBe(array_slice($ids, 20, 20))
        ->and($second['next_before'])->toBe($ids[39])
        ->and(array_column($third['sessions'], 'id'))->toBe(array_slice($ids, 40))
        ->and($third['next_before'])->toBeNull()
        ->and(array_merge(...array_map(fn ($page) => array_column($page['sessions'], 'id'), everyPage($this, $user, $bench))))->toBe($ids);
});

it('ANA-009 has no next_before for a user with 20 sessions or fewer', function () {
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();
    $squat = Exercise::factory()->create(['name' => 'Squat']);
    benchSessions($user, $squat, 20);

    expect(historyOf($this, $user, $bench)->assertOk()->json('data.next_before'))->toBeNull()
        ->and(historyOf($this, $user, $squat)->assertOk()->json('data'))
        ->sessions->toHaveCount(20)
        ->next_before->toBeNull();
});

it('EX-002 orders sessions of the same date by id, newest id first, and the undated last, paging through both', function () {
    ['user' => $user] = ProgressSeed::world();
    $bench = Exercise::factory()->create(['name' => 'Squat']);
    $dated = benchSessions($user, $bench, 5);
    $tied = array_column(benchSessions($user, $bench, 18, '2026-09-01 09:00:00'), 'id');
    $undated = array_column(benchSessions($user, $bench, 20, null), 'id');
    rsort($tied, SORT_STRING);
    rsort($undated, SORT_STRING);
    $expected = [...array_column($dated, 'id'), ...$tied, ...$undated];

    $pages = everyPage($this, $user, $bench);

    // Page one ends inside the tie (5 dated + 15 of 18 tied), page two inside the undated (3 tied + 17 undated).
    expect($pages)->toHaveCount(3)
        ->and(array_merge(...array_map(fn ($page) => array_column($page['sessions'], 'id'), $pages)))->toBe($expected)
        ->and($pages[0]['next_before'])->toBe($tied[14])
        ->and($pages[1]['next_before'])->toBe($undated[16])
        ->and(array_column($pages[2]['sessions'], 'date_label'))->toBe(['Session', 'Session', 'Session']);
});

it('ANA-009 gives every session the same best and labels on its page as the unpaged history does', function () {
    ['user' => $user] = ProgressSeed::world();
    $bench = Exercise::factory()->create(['name' => 'Squat', 'muscle_group' => MuscleGroup::Quads]);
    benchSessions($user, $bench, 45);
    $all = WorkoutSet::query()->where('user_id', $user->id)->where('exercise_id', $bench->id)->where('is_logged', true)
        ->with('session')->orderBy('position')->orderBy('id')->get();

    $paged = array_merge(...array_map(fn ($page) => $page['sessions'], everyPage($this, $user, $bench)));

    expect($paged)->toHaveCount(45)
        ->and($paged)->toBe(ExerciseHistoryPayload::build($bench, $all, WeightUnit::Kg)->toArray()['sessions']);
});

it('EX-002 never shows a stranger\'s sessions on any page, though they are dated between the viewer\'s', function () {
    ['user' => $user] = ProgressSeed::world();
    $bench = Exercise::factory()->create(['name' => 'Squat']);
    $mine = array_column(benchSessions($user, $bench, 45), 'id');
    $stranger = ProgressSeed::stranger();
    benchSessions($stranger, $bench, 45);

    $seen = array_merge(...array_map(fn ($page) => array_column($page['sessions'], 'id'), everyPage($this, $user, $bench)));

    expect($seen)->toBe($mine);
});

it('ANA-009 answers 404 for a before that is a stranger\'s session, unknown or deleted, and reads the first page for a null before', function () {
    ['user' => $user, 'bench' => $bench, 'benchSessions' => [$first, $second]] = ProgressSeed::world();
    $theirs = WorkoutSession::factory()->for(ProgressSeed::stranger())->create(['actual_start_at' => '2026-10-01 09:00:00']);
    $deleted = WorkoutSession::factory()->for($user)->create(['actual_start_at' => '2026-10-07 09:00:00']);
    $deleted->delete();

    // The ownership check refuses it before the read runs (CAP-020 step 2).
    expect(app(Ownership::class)->allows('exercise_history_show', ['exercise_id' => (string) $bench->id, 'before' => $theirs->id], $user))->toBeFalse()
        ->and(app(Ownership::class)->allows('exercise_history_show', ['exercise_id' => (string) $bench->id, 'before' => $deleted->id], $user))->toBeFalse()
        ->and(app(Ownership::class)->allows('exercise_history_show', ['exercise_id' => (string) $bench->id, 'before' => $second->id], $user))->toBeTrue();
    historyOf($this, $user, $bench, $theirs->id)->assertNotFound();
    historyOf($this, $user, $bench, '0193f1a2-0000-7000-8000-000000000000')->assertNotFound();
    historyOf($this, $user, $bench, $deleted->id)->assertNotFound();
    historyOf($this, $user, $bench, $second->id)->assertOk()->assertJsonPath('data.sessions.0.id', $first->id);
    expect(array_column(historyOf($this, $user, $bench, null)->assertOk()->json('data.sessions'), 'id'))->toBe([$second->id, $first->id]);
});

it('ANA-009 runs as many queries for 45 sessions as for 3, and loads sets of at most 20 sessions', function () {
    ['user' => $few] = ProgressSeed::world();
    $bench = Exercise::factory()->create(['name' => 'Squat']);
    benchSessions($few, $bench, 3);
    $many = ProgressSeed::planned(User::factory()->create());
    benchSessions($many, $bench, 45);

    $queries = function (User $user) use ($bench): int {
        historyOf($this, $user, $bench)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        historyOf($this, $user, $bench)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queries($many))->toBe($queries($few))
        ->and(ExerciseHistorySnapshot::load($many, (string) $bench->id)->sets->pluck('workout_session_id')->unique())->toHaveCount(20);
});

it('EX-002 dates a session with only a planned start by that start, on every page and as the cursor', function () {
    ['user' => $user] = ProgressSeed::world();
    $bench = Exercise::factory()->create(['name' => 'Squat']);
    $ids = array_column(benchSessions($user, $bench, 25), 'id');
    // Between day 19 (2026-09-21) and day 20 (2026-09-20 09:00): the 20th session, so the first page ends on it.
    $planned = WorkoutSession::factory()->for($user)->create(['actual_start_at' => null, 'planned_start_at' => '2026-09-20 12:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $planned->id, 'exercise_id' => $bench->id, 'position' => 1, 'actual_weight_kg' => 50, 'actual_reps' => 5, 'is_logged' => true]);

    $pages = everyPage($this, $user, $bench);

    expect($pages[0]['next_before'])->toBe($planned->id)
        ->and($pages[0]['sessions'][19]['date_label'])->toBe('Sun, 20 Sep 2026')
        ->and(array_merge(...array_map(fn ($page) => array_column($page['sessions'], 'id'), $pages)))
        ->toBe([...array_slice($ids, 0, 19), $planned->id, ...array_slice($ids, 19)]);
});
