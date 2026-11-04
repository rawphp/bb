<?php

use App\Domain\Model\MuscleGroup;
use App\Models\DayTarget;
use App\Models\Exercise;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\PlannedFood;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Support\Carbon;
use Tests\Support\ProgressSeed;
use Tests\Support\ScreenFixture;
use Tests\Support\TrainSeed;

// The HTTP seam of progress_show and its screen fixtures. The rules are unit tests (ProgressChartPayloadTest and
// its neighbours).

beforeEach(fn () => Carbon::setTestNow(ProgressSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

it('ANA-001 refuses progress_show when signed out', function () {
    $this->postJson('/capabilities/progress_show')->assertUnauthorized();
});

it('ANA-001 answers 422 naming scope for a scope that is neither phase nor plan', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/progress_show', ['scope' => 'week'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'scope');
});

it('ANA-001 ANA-002 ANA-003 ANA-004 ANA-005 ANA-006 ANA-007 ANA-009 ANA-010 shows a seeded plan with the clock fixed', function () {
    ['user' => $user] = ProgressSeed::world();

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect($data['empty'])->toBeNull()
        ->and($data['scopes'][0]['selected'])->toBeTrue()
        ->and($data['chart']['trend'][0]['date'])->toBe('2026-09-01')
        ->and($data['chart']['trend'])->toHaveCount(40)
        ->and(array_column($data['chart']['waist'], 'value'))->toEqual([88.0, 87.5, 87.5, 87.0])
        ->and(array_column($data['summary'], 'value'))->toBe(['80.0 kg', '+0.0 kg', '76.8 kg'])
        ->and($data['share']['message'])->toBe('Fat loss: 80.0 kg to 80.0 kg (+0.0 kg) over 5 weeks')
        ->and($data['this_week']['calories']['value_label'])->toBe('5010/18550')
        ->and($data['this_week']['days'][0]['date'])->toBe('2026-10-10')
        ->and($data['review_card']['title'])->toBe('Weekly review available')
        ->and($data['steps']['bar']['value_label'])->toBe('6,240/10,000')
        ->and($data['phase_history'][0]['title'])->toBe('Maintenance')
        ->and(array_column($data['mesocycle_history'], 'title'))->toBe(['Cut A', 'Base'])
        ->and(array_column($data['mesocycle_history'][0]['sets'], 'label'))->toBe(['Chest', 'Back', 'Biceps'])
        ->and(array_column($data['exercises'], 'name'))->toBe(['Barbell Row', 'Bench Press', 'Curl']);
});

it('ANA-001 draws the whole plan when asked', function () {
    ['user' => $user] = ProgressSeed::world();

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show', ['scope' => 'plan'])->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect($data['scopes'][1]['selected'])->toBeTrue()
        ->and($data['chart']['trend'])->toHaveCount(40);
});

it('ONB-032 WT-012 shows the empty state under four weigh-ins in the empty fixture\'s shape', function () {
    ['user' => $user] = ProgressSeed::world();
    WeighIn::query()->where('user_id', $user->id)->where('weighed_at', '>', '2026-09-03 23:59:59')->delete();

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('progress_show.empty'), $data))->toBe([])
        ->and($data['empty'])->toBe(['message' => 'Log 4 weigh-ins to see your trend', 'action_title' => 'Log a weigh-in'])
        ->and($data['chart'])->toBeNull()
        ->and($data['share'])->toBeNull();
});

it('ANA-001 says the phase has no weigh-ins in the scope-empty fixture\'s shape', function () {
    ['user' => $user] = ProgressSeed::world();
    // Weigh-ins only before the fat-loss phase began on 1 September.
    WeighIn::query()->where('user_id', $user->id)->delete();
    foreach (range(0, 5) as $offset) {
        WeighIn::factory()->for($user)->create(['weight_kg' => 82, 'weighed_at' => date('Y-m-d', strtotime("2026-08-10 +{$offset} days")).' 07:00:00']);
    }

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('progress_show.scope_empty'), $data))->toBe([])
        ->and($data['scope_message'])->toBe('No weigh-ins in this phase yet')
        ->and($data['chart'])->toBeNull();
});

it('ANA-003 DAY-011 counts this week\'s calories as Today\'s totals bar does, planned meals included unless set to checked in', function () {
    ['user' => $user] = ProgressSeed::world();
    // Today, 10 October, the first day of the week: 2410 off-plan, breakfast checked in at 500, lunch planned as 80 g
    // of oats (304, not its 780 target) and dinner not planned or checked in (its 1040 target).
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-10-10')->firstOrFail();
    [$breakfast, $lunch] = array_column($day->meals, 'id');
    MealCheckIn::factory()->create(['meal_id' => $breakfast, 'time' => '08:10', 'kcal' => 500, 'protein_g' => 30, 'fat_g' => 15, 'carbs_g' => 60, 'is_manual' => false]);
    $oats = Food::factory()->createOne(['name' => 'Oats', 'kcal' => 380, 'protein' => 13, 'fat' => 7, 'carbs' => 66]);
    PlannedFood::factory()->create(['meal_id' => $lunch, 'food_id' => $oats->getKey(), 'grams' => 80]);
    $this->actingAs($user);

    $week = $this->postJson('/capabilities/progress_show')->assertOk()->json('data.this_week.calories.value_label');
    $today = $this->postJson('/capabilities/today_show')->assertOk()->json('data.totals.calories.value_text');

    expect($week)->toBe('4254/18550')
        ->and(explode('/', $week)[0])->toBe(explode('/', $today)[0]);

    Profile::query()->where('user_id', $user->id)->update(['consumed_totals' => 'checked_in']);

    expect($this->postJson('/capabilities/progress_show')->assertOk()->json('data.this_week.calories.value_label'))->toBe('2910/18550');
});

it('ANA-003 shows a user with no plan the empty fixture\'s shape without a week card', function () {
    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/progress_show')->assertOk()->json('data');

    assertMatchesScreenFixture('progress_show', $data);
    expect($data['empty'])->not->toBeNull()
        ->and($data['this_week'])->toBeNull()
        ->and($data['review_card'])->toBeNull()
        ->and($data['exercises'])->toBe([]);
});

it('ANA-001 keeps another user\'s weigh-ins, steps, mesocycles and sets out of the tab', function () {
    ProgressSeed::stranger();
    $mine = ProgressSeed::planned(User::factory()->create());

    $data = $this->actingAs($mine)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    expect($data['empty'])->not->toBeNull()
        ->and($data['chart'])->toBeNull()
        ->and($data['steps'])->toBeNull()
        ->and($data['phase_history'])->toBe([])
        ->and($data['mesocycle_history'])->toBe([])
        ->and($data['exercises'])->toBe([]);
});

it('ANA-009 EX-013 lists the exercises by the latest session the user logged a set in, leaving out deleted sessions', function () {
    ['user' => $user, 'bench' => $bench, 'row' => $row, 'curl' => $curl] = ProgressSeed::world();
    // A later planned-only session, deleted: it must not make Bench the most recent.
    $later = WorkoutSession::factory()->for($user)->create(['actual_start_at' => null, 'planned_start_at' => '2026-10-12 09:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $later->id, 'exercise_id' => $bench->id, 'is_logged' => true, 'actual_weight_kg' => 60, 'actual_reps' => 8]);
    $later->delete();
    // A planned-only session after the others, with an unlogged set: Curl stays where it was.
    $planned = WorkoutSession::factory()->for($user)->create(['actual_start_at' => null, 'planned_start_at' => '2026-10-13 09:00:00']);
    WorkoutSet::factory()->create(['workout_session_id' => $planned->id, 'exercise_id' => $curl->id, 'is_logged' => false]);
    $unusedLibrary = Exercise::factory()->create();

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    expect(array_column($data['exercises'], 'id'))->toBe([(string) $row->id, (string) $bench->id, (string) $curl->id])
        ->and(array_column($data['exercises'], 'id'))->not->toContain((string) $unusedLibrary->id);
});

it('ANA-005 shows no steps when none are stored for today', function () {
    ['user' => $user] = ProgressSeed::world();
    Step::query()->where('user_id', $user->id)->delete();

    expect($this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data.steps'))->toBeNull();
});

it('ANA-005 shows today\'s steps, not those of another day', function () {
    ['user' => $user] = ProgressSeed::world();
    Step::factory()->for($user)->create(['date' => '2026-10-09', 'count' => 9999]);

    expect($this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data.steps.bar.value_label'))->toBe('6,240/10,000');
});

it('ANA-005 shows no steps when only yesterday\'s are stored', function () {
    ['user' => $user] = ProgressSeed::world();
    Step::query()->where('user_id', $user->id)->update(['date' => '2026-10-09']);

    expect($this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data.steps'))->toBeNull();
});

it('EX-013 dates an exercise by its planned start when the session has no actual one, and by the actual start when it has both', function () {
    ['user' => $user, 'bench' => $bench, 'row' => $row, 'curl' => $curl] = ProgressSeed::world();
    $logged = ['is_logged' => true, 'actual_weight_kg' => 40, 'actual_reps' => 10];
    // Curl, last logged on 5 October, is also in a planned-only session on 12 October: it moves to the top.
    $planned = WorkoutSession::factory()->for($user)->create(['actual_start_at' => null, 'planned_start_at' => '2026-10-12 09:00:00']);
    WorkoutSet::factory()->create([...$logged, 'workout_session_id' => $planned->id, 'exercise_id' => $curl->id]);
    // Bench has an actual start on 8 October and a planned start later: the actual one counts, so Bench stays
    // behind Row (9 October) and the planned 26 October does not move Bench to the top.
    $both = WorkoutSession::factory()->for($user)->create(['actual_start_at' => '2026-10-08 20:00:00', 'planned_start_at' => '2026-10-26 09:00:00']);
    WorkoutSet::factory()->create([...$logged, 'workout_session_id' => $both->id, 'exercise_id' => $bench->id]);

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    expect(array_column($data['exercises'], 'id'))->toBe([(string) $curl->id, (string) $row->id, (string) $bench->id]);
});

it('ANA-007 WO-025 averages a mesocycle\'s sets without a deleted session\'s sets, a logged-and-skipped set or an unlogged one', function () {
    ['user' => $user, 'bench' => $bench] = ProgressSeed::world();
    $cutA = $user->mesocycles()->where('name', 'Cut A')->firstOrFail();
    // A deleted Cut A session holding three logged Bench sets, and a skipped and an unlogged Bench set in a live one.
    $gone = ProgressSeed::session($user, $cutA, 1, '2026-10-06 09:00:00', [[$bench, 60, 8], [$bench, 60, 8], [$bench, 60, 8]]);
    $gone->delete();
    $live = WorkoutSession::query()->where('mesocycle_id', $cutA->id)->where('week', 2)->firstOrFail();
    WorkoutSet::factory()->create(['workout_session_id' => $live->id, 'exercise_id' => $bench->id, 'position' => 9, 'is_logged' => true, 'is_skipped' => true, 'actual_weight_kg' => 60, 'actual_reps' => 8]);
    WorkoutSet::factory()->create(['workout_session_id' => $live->id, 'exercise_id' => $bench->id, 'position' => 10, 'is_logged' => false, 'is_skipped' => false]);

    $sets = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data.mesocycle_history.0.sets');

    // Chest 5 working sets, Back 1 and Biceps 1, over 2 weeks.
    expect($sets)->toBe([
        ['label' => 'Chest', 'value' => '2.5', 'tone' => 'neutral'],
        ['label' => 'Back', 'value' => '0.5', 'tone' => 'neutral'],
        ['label' => 'Biceps', 'value' => '0.5', 'tone' => 'neutral'],
    ]);
});

it('EX-013 lists an exercise logged only in a session with no mesocycle, at its date', function () {
    ['user' => $user, 'bench' => $bench, 'row' => $row, 'curl' => $curl] = ProgressSeed::world();
    $squat = TrainSeed::exercise('Squat', MuscleGroup::Quads);
    $session = WorkoutSession::factory()->for($user)->create(['mesocycle_id' => null, 'actual_start_at' => '2026-10-07 09:00:00', 'planned_start_at' => null]);
    WorkoutSet::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $squat->id, 'is_logged' => true, 'actual_weight_kg' => 100, 'actual_reps' => 5]);

    $data = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    expect(array_column($data['exercises'], 'id'))->toBe([(string) $row->id, (string) $bench->id, (string) $squat->id, (string) $curl->id]);
});

it('ANA-001 ANA-007 EX-013 keeps a set out of the tab when it names the viewer but its session belongs to another user', function () {
    ['user' => $viewer, 'bench' => $bench] = ProgressSeed::world();
    $cutA = $viewer->mesocycles()->where('name', 'Cut A')->firstOrFail();
    $before = $this->actingAs($viewer)->postJson('/capabilities/progress_show')->assertOk()->json('data');
    // Another user's session, dated and in the viewer's mesocycle, holding logged sets whose user_id is the viewer's:
    // Bench for the mesocycle average, and Squat, held by nothing else, for the exercise list.
    $squat = TrainSeed::exercise('Squat', MuscleGroup::Quads);
    $session = ProgressSeed::session(User::factory()->create(), $cutA, 1, '2026-10-20 09:00:00', [[$bench, 60, 8], [$bench, 60, 8], [$squat, 100, 5]]);
    WorkoutSet::query()->where('workout_session_id', $session->id)->update(['user_id' => $viewer->id]);

    $after = $this->actingAs($viewer)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    expect($after['mesocycle_history'])->toBe($before['mesocycle_history'])
        ->and($after['exercises'])->toBe($before['exercises'])
        ->and(array_column($after['exercises'], 'name'))->not->toContain('Squat');
});

it('ANA-001 ANA-007 EX-013 keeps a set out of the tab when it sits in the viewer\'s session but names another user', function () {
    ['user' => $viewer, 'bench' => $bench] = ProgressSeed::world();
    $cutA = $viewer->mesocycles()->where('name', 'Cut A')->firstOrFail();
    $before = $this->actingAs($viewer)->postJson('/capabilities/progress_show')->assertOk()->json('data');
    $squat = TrainSeed::exercise('Squat', MuscleGroup::Quads);
    $session = ProgressSeed::session($viewer, $cutA, 1, '2026-10-20 09:00:00', [[$bench, 60, 8], [$bench, 60, 8], [$squat, 100, 5]]);
    WorkoutSet::query()->where('workout_session_id', $session->id)->update(['user_id' => User::factory()->create()->id]);

    $after = $this->actingAs($viewer)->postJson('/capabilities/progress_show')->assertOk()->json('data');

    expect($after['mesocycle_history'])->toBe($before['mesocycle_history'])
        ->and($after['exercises'])->toBe($before['exercises'])
        ->and(array_column($after['exercises'], 'name'))->not->toContain('Squat');
});

/**
 * SET-012: ProgressSeed's user in `$zone` at 2026-10-09 21:58 UTC (Saturday 10 Oct 07:58 in Brisbane), with only these
 * weigh-ins: 06:30 Brisbane on Wed 7 to Sat 10 Oct with a waist, and 21:00 Brisbane on Fri 9 Oct without one.
 */
function progressLocalDayUser(string $zone): User
{
    ['user' => $user] = ProgressSeed::world();
    $user->forceFill(['time_zone' => $zone])->save();
    Carbon::setTestNow('2026-10-09 21:58:00');
    WeighIn::query()->where('user_id', $user->id)->delete();
    foreach ([
        ['2026-10-06 20:30:00', 80.0, 90.0],
        ['2026-10-07 20:30:00', 79.8, 89.5],
        ['2026-10-08 20:30:00', 79.6, 89.0],
        ['2026-10-09 11:00:00', 80.4, null],
        ['2026-10-09 20:30:00', 79.4, 88.5],
    ] as [$at, $kg, $waist]) {
        WeighIn::factory()->for($user)->create(['weighed_at' => $at, 'weight_kg' => $kg, 'waist_cm' => $waist]);
    }

    return $user;
}

it('SET-012 draws the Progress chart\'s weigh-ins and trend on the user\'s local day', function (string $zone, array $dates, array $weights, float $lastTrend) {
    $user = progressLocalDayUser($zone);

    $chart = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data.chart');

    expect(array_column($chart['weights'], 'date'))->toBe($dates)
        ->and(array_column($chart['weights'], 'value'))->toEqual($weights)
        ->and(array_column($chart['trend'], 'date'))->toBe($dates)
        ->and($chart['trend'][array_key_last($chart['trend'])]['value'])->toEqual($lastTrend);
})->with([
    'Brisbane' => ['Australia/Brisbane', ['2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'], [80.0, 79.8, 79.6, 79.4], 79.89],
    'UTC' => ['UTC', ['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'], [80.0, 79.8, 79.6, 80.4], 79.99],
]);

it('SET-012 draws the waist line on the user\'s local day', function (string $zone, array $dates) {
    $user = progressLocalDayUser($zone);

    $waist = $this->actingAs($user)->postJson('/capabilities/progress_show')->assertOk()->json('data.chart.waist');

    expect(array_column($waist, 'date'))->toBe($dates)
        ->and(array_column($waist, 'value'))->toEqual([90.0, 89.5, 89.0, 88.5]);
})->with([
    'Brisbane' => ['Australia/Brisbane', ['2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10']],
    'UTC' => ['UTC', ['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09']],
]);
