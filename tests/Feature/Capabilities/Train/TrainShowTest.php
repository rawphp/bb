<?php

use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Support\TrainSeed;

// The HTTP seam of train_show and its screen fixtures. The rules are unit tests (TrainPayloadTest).

afterEach(fn () => Carbon::setTestNow());

/** What train_show offers as today's session and what today_show's workout row names, side by side. */
function trainAndToday(User $user): array
{
    $train = test()->actingAs($user)->postJson('/capabilities/train_show')->assertOk()->json('data.todays_session');
    $workout = test()->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data.workouts.0');

    return [
        $train,
        $workout === null ? null : [
            'title' => $workout['title'], 'mesocycle_id' => $workout['mesocycle_id'], 'week' => $workout['week'], 'day' => $workout['day'],
        ],
    ];
}

it('NAV-002 refuses train_show when signed out', function () {
    $this->postJson('/capabilities/train_show')->assertUnauthorized();
});

it('CAP-012 answers 422 for a field train_show does not take', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/train_show', ['mesocycle_id' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'mesocycle_id');
});

it('MESO-001 MESO-002 shows an empty Train tab to a new user', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');

    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/train_show')->assertOk()->json('data');

    assertMatchesScreenFixture('train_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('train_show.empty'), $data))->toBe([]);
    expect($data['sections'])->toBe([])
        ->and($data['create_routes'][1]['available'])->toBeFalse();
});

it('MESO-001 MESO-007 MESO-060 MESO-064 shows the deload week\'s card with the next-mesocycle prompt, the draft and the list', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    $user = TrainSeed::user();
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-07-27');
    $current = TrainSeed::mesocycle($user, ['name' => 'Hypertrophy Block', 'phase_id' => $gain->id]);
    TrainSeed::mesocycle($user, ['name' => 'Foundation Block', 'start_date' => '2026-07-27', 'phase_id' => $gain->id]);
    TrainSeed::mesocycle($user, ['name' => 'Hypertrophy Block 3', 'is_draft' => true, 'start_date' => null, 'weeks' => null, 'route' => 'builder']);
    TrainSeed::trained($current, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($current, 4, 1, '2026-09-28', flat: true);

    $data = $this->actingAs($user)->postJson('/capabilities/train_show')->assertOk()->json('data');

    assertMatchesScreenFixture('train_show', $data);
    expect($data['current']['week_text'])->toBe('Week 5 of 5 · Deload')
        ->and($data['current']['dates_text'])->toBe('7 Sep – 11 Oct 2026')
        ->and($data['current']['next_mesocycle']['starts_text'])->toBe('Starts Monday 12 Oct')
        ->and($data['current']['next_mesocycle']['active_rest']['tone'])->toBe('info')
        ->and($data['draft']['name'])->toBe('Hypertrophy Block 3')
        ->and(array_column($data['sections'], 'status'))->toBe(['current', 'past'])
        ->and($data['create_routes'][1]['copy_source_id'])->toBe($current->id);
});

it('NAV-002 MESO-065 shows today\'s session and the priority reset a copy would bring', function () {
    Carbon::setTestNow('2026-10-08 10:00:00');
    $user = TrainSeed::user();
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-07-27', '2026-10-12');
    TrainSeed::phase($user, 'fatLoss', '2026-10-12');
    $current = TrainSeed::mesocycle($user, ['start_date' => '2026-09-14', 'phase_id' => $gain->id]);

    $data = $this->actingAs($user)->postJson('/capabilities/train_show')->assertOk()->json('data');

    assertMatchesScreenFixture('train_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('train_show.warning'), $data))->toBe([]);
    expect($data['todays_session'])->toBe(['title' => 'Week 4 · Day 2 · Lower A', 'mesocycle_id' => $current->id, 'week' => 4, 'day' => 2])
        ->and($data['create_routes'][1]['priority_notice'])->toBe('Priorities reset for Fat loss. Your old priorities can be kept.');
});

it('MESO-001 lists only the user\'s own mesocycles', function () {
    Carbon::setTestNow('2026-10-08 10:00:00');
    TrainSeed::mesocycle(User::factory()->create());

    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/train_show')->assertOk()->json('data');

    expect($data['sections'])->toBe([])
        ->and(Mesocycle::query()->count())->toBe(1);
});

it('NAV-002 SCH-040 SCH-050 a session moved to today is the one Train and Today both name', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    // Week 3's Lower A moved from Thursday 24 September to today, Wednesday 23 September.
    WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'is_skipped' => false,
        'planned_start_at' => '2026-09-23 17:30:00', 'planned_end_at' => '2026-09-23 18:30:00', 'actual_start_at' => null, 'actual_end_at' => null]);

    [$train, $today] = trainAndToday($user);

    expect($train)->toBe(['title' => 'Week 3 · Day 2 · Lower A', 'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2])
        ->and($today)->toBe($train);
});

it('NAV-002 SCH-060 after a paused week Train and Today both name the same mesocycle week', function () {
    Carbon::setTestNow('2026-09-24 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-14', 'is_away' => true, 'away_training' => 'pause']);

    [$train, $today] = trainAndToday($user);

    expect($train)->toBe(['title' => 'Week 2 · Day 2 · Lower A', 'mesocycle_id' => $mesocycle->id, 'week' => 2, 'day' => 2])
        ->and($today)->toBe($train);
});

it('NAV-002 SCH-040 on a rest day neither Train nor Today names a session', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);

    expect(trainAndToday($user))->toBe([null, null]);
});
