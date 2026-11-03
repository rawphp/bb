<?php

use App\Actions\Plan\RebuildTargets;
use App\Domain\Model\DayType;
use App\Domain\Plan\PlanRebuildRefused;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\OtherActivity;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

// The world of rp-clone's PlanRebuildTests: the plan starts Monday 2026-09-07 and reviews on Mondays, one Fat loss phase
// with no end, Sam trains Thursdays at 17:30 and eats 07:00 to 21:00 in four meals. Today is Wednesday 2026-10-14.

const REBUILD_PHASE = '11111111-1111-4111-8111-111111111111';
const REBUILD_MESO = '44444444-4444-4444-8444-444444444444';

/** @param  array<string, mixed>  $profile */
function rebuildUser(array $profile = [], bool $phase = true): User
{
    $user = User::factory()->create();
    Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);
    Profile::factory()->for($user)->create(array_replace([
        'name' => 'Sam', 'eating_window' => ['start' => '07:00', 'end' => '21:00'], 'meal_count' => 4,
        'training_days' => [['weekday' => 4, 'start' => '17:30', 'minutes' => 60]],
        'step_plan' => array_map(fn (int $weekday) => ['weekday' => $weekday, 'min' => 6000, 'max' => 10000], range(1, 7)),
    ], $profile));
    if ($phase) {
        Phase::factory()->for($user)->create([
            'id' => REBUILD_PHASE, 'type' => 'fatLoss', 'start_date' => '2026-09-07', 'end_date' => null, 'target_rate_percent_per_week' => -0.5,
        ]);
    }

    return $user;
}

/** One chest day of 12 sets on ISO weekday 4, four weeks from 2026-10-12. */
function rebuildSeedMesocycle(User $user, array $with = []): Mesocycle
{
    return Mesocycle::factory()->for($user)->create(array_replace([
        'id' => REBUILD_MESO, 'phase_id' => REBUILD_PHASE, 'name' => 'Meso 1', 'weeks' => 4, 'start_date' => '2026-10-12',
        'days' => [['id' => 'push', 'label' => 'Push', 'weekday' => 4, 'slots' => [
            ['id' => 'push-chest', 'exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 12],
        ]]],
        'muscle_priorities' => null,
    ], $with));
}

/** @return list<string> */
function rebuildRun(User $user, float $change = 0, bool $beforeStart = false): array
{
    return app(RebuildTargets::class)($user, $change, $beforeStart);
}

/** @return Collection<string, DayTarget> the user's day targets by Y-m-d */
function rebuildRows(User $user): Collection
{
    return DayTarget::query()->where('user_id', $user->id)->orderBy('date')->get()
        ->keyBy(fn (DayTarget $row) => $row->date->format('Y-m-d'));
}

/** @return array<string, DayType> */
function rebuildTypes(User $user): array
{
    return rebuildRows($user)->map(fn (DayTarget $row) => $row->day_type)->all();
}

/** @return array<string, float> */
function rebuildKcal(User $user): array
{
    return rebuildRows($user)->map(fn (DayTarget $row) => $row->kcal)->all();
}

it('SET-004 a stored date is updated by id and a new date is created', function () {
    $user = rebuildUser();
    $stored = [];
    foreach (['2026-10-14', '2026-10-15', '2026-10-17', '2026-10-19'] as $date) {
        $stored[$date] = DayTarget::factory()->for($user)->create(['date' => $date]);
    }

    expect(rebuildRun($user))->toBe(['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18']);

    $rows = rebuildRows($user);
    expect($rows->keys()->all())->toBe(['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19'])
        ->and($rows['2026-10-15']->id)->toBe($stored['2026-10-15']->id)
        ->and($rows['2026-10-17']->id)->toBe($stored['2026-10-17']->id)
        ->and($rows['2026-10-16']->id)->not->toBeIn(array_map(fn (DayTarget $row) => $row->id, $stored))
        // Today's and next week's stay as they were.
        ->and($rows['2026-10-14']->kcal)->toBe(2000.0)
        ->and($rows['2026-10-19']->kcal)->toBe(2000.0)
        ->and($rows['2026-10-15']->kcal)->not->toBe(2000.0)
        ->and($rows['2026-10-15']->meals)->toHaveCount(4);
});

it('SET-004 the trend weight replaces the profile weight', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $plain = rebuildKcal($user);

    WeighIn::factory()->for($user)->create(['weight_kg' => 85, 'weighed_at' => '2026-10-13 07:00:00']);
    rebuildRun($user);

    expect(rebuildKcal($user)['2026-10-15'])->toBeGreaterThan($plain['2026-10-15']);
});

it('SET-004 a diet break week runs at maintenance', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $deficit = rebuildKcal($user);

    PlanWeek::factory()->for($user)->create(['start_date' => '2026-10-12', 'is_diet_break' => true]);
    rebuildRun($user);

    expect(rebuildKcal($user)['2026-10-15'])->toBeGreaterThan($deficit['2026-10-15']);
});

it('SET-004 a week flagged a deload runs at maintenance like the review builds it', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $deficit = rebuildKcal($user);

    $week = PlanWeek::factory()->for($user)->create(['start_date' => '2026-10-12', 'is_deload' => true]);
    rebuildRun($user);
    $deload = rebuildKcal($user);

    $week->update(['is_deload' => false, 'is_diet_break' => true]);
    rebuildRun($user);

    expect($deload['2026-10-15'])->toBeGreaterThan($deficit['2026-10-15'])
        ->and($deload)->toBe(rebuildKcal($user));
});

it('SAFE-003 a training-only profile has no targets to rebuild', function () {
    $user = rebuildUser(['training_only' => true]);

    expect(rebuildRun($user))->toBe([])
        ->and(DayTarget::query()->count())->toBe(0);
});

it('SET-004 the mesocycle covering the week places its session on the training day', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user);

    rebuildRun($user);

    $types = rebuildTypes($user);
    expect($types['2026-10-15'])->not->toBe(DayType::Rest)
        ->and($types['2026-10-16'])->toBe(DayType::Rest)
        ->and($types['2026-10-18'])->toBe(DayType::Rest);
});

it('SCH-039 the rebuild reads the week\'s sessions from the stored week lists', function () {
    $user = rebuildUser(['training_days' => [
        ['weekday' => 4, 'start' => '17:30', 'minutes' => 60],
        ['weekday' => 6, 'start' => '17:30', 'minutes' => 60],
    ]]);
    $days = [
        ['id' => 'push', 'label' => 'Push', 'weekday' => 4, 'slots' => [['id' => 'push-chest', 'exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 12]]],
        ['id' => 'pull', 'label' => 'Pull', 'weekday' => 6, 'slots' => [['id' => 'pull-back', 'exercise_id' => null, 'muscle_group' => 'back', 'sets' => 12]]],
    ];
    $meso = rebuildSeedMesocycle($user, ['days' => $days]);

    // The whole rotation: both training days run a session.
    rebuildRun($user);
    $whole = rebuildTypes($user);

    // The week's list names only Push (the first of three weeks listed).
    $meso->update(['later_weeks' => [['push'], ['push'], ['push']]]);
    rebuildRun($user);
    $listed = rebuildTypes($user);

    expect($whole['2026-10-15'])->not->toBe(DayType::Rest)
        ->and($whole['2026-10-17'])->not->toBe(DayType::Rest)
        ->and($listed['2026-10-15'])->not->toBe(DayType::Rest)
        ->and($listed['2026-10-17'])->toBe(DayType::Rest);
});

it('SET-004 a draft mesocycle adds no workouts', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user, ['is_draft' => true]);

    rebuildRun($user);

    expect(rebuildTypes($user))->each->toBe(DayType::Rest);
});

it('SET-004 a mesocycle that does not cover the week adds no workouts', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user, ['start_date' => '2026-11-02']);

    rebuildRun($user);

    expect(rebuildTypes($user))->each->toBe(DayType::Rest);
});

it('PLAN-002 a mesocycle that began before the plan ends on its own dates', function () {
    // Started a week before the plan's 7 Sep, its 6 weeks end on 11 Oct: the week of Oct 12 to 18 is not in it.
    $user = rebuildUser();
    rebuildSeedMesocycle($user, ['start_date' => '2026-08-31', 'weeks' => 6]);

    rebuildRun($user);

    expect(rebuildTypes($user))->each->toBe(DayType::Rest);
});

it('PLAN-002 a later mesocycle with no days does not take over the week', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user, ['start_date' => '2026-10-05']);
    Mesocycle::factory()->for($user)->create([
        'phase_id' => null, 'name' => 'Meso 2', 'weeks' => 4, 'start_date' => '2026-10-12', 'days' => [], 'muscle_priorities' => null,
    ]);

    rebuildRun($user);

    expect(rebuildTypes($user)['2026-10-15'])->not->toBe(DayType::Rest);
});

it('SCH-051 a session moved by its row trains on the new day', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user);
    // The Thursday session moved to Saturday 17 Oct at 10:00.
    WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => REBUILD_MESO, 'week' => 1, 'day' => 1, 'planned_start_at' => '2026-10-17 10:00:00', 'planned_end_at' => '2026-10-17 11:00:00',
    ]);

    rebuildRun($user);

    $types = rebuildTypes($user);
    expect($types['2026-10-15'])->toBe(DayType::Rest)
        ->and($types['2026-10-17'])->not->toBe(DayType::Rest);
});

it('SCH-053 a skipped session leaves a rest day', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user);
    WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => REBUILD_MESO, 'week' => 1, 'day' => 1, 'planned_start_at' => null, 'planned_end_at' => null, 'is_skipped' => true,
    ]);

    rebuildRun($user);

    expect(rebuildTypes($user))->each->toBe(DayType::Rest);
});

it('SET-004 a rebuild keeps the other activity\'s energy', function () {
    // Friday 16 Oct is a rest day; a stored 60-minute moderate run is 64 kg x 5 = 320 kcal on top of it.
    $user = rebuildUser();
    rebuildRun($user);
    $plain = rebuildRows($user)->map->only(['kcal', 'carbs_g', 'day_type'])->all();

    OtherActivity::factory()->for($user)->create(['date' => '2026-10-16', 'minutes' => 60, 'intensity' => 'moderate']);
    rebuildRun($user);
    $kept = rebuildRows($user);

    expect($kept['2026-10-16']->kcal - $plain['2026-10-16']['kcal'])->toEqualWithDelta(320, 1e-6)
        ->and($kept['2026-10-16']->carbs_g)->toBeGreaterThan($plain['2026-10-16']['carbs_g'])
        ->and($kept['2026-10-16']->day_type)->toBe(DayType::Moderate)
        ->and($kept['2026-10-17']->kcal)->toEqualWithDelta($plain['2026-10-17']['kcal'], 1e-6);
});

it('SET-004 a refusal writes nothing and says why', function () {
    $user = rebuildUser(phase: false);

    expect(fn () => rebuildRun($user))
        ->toThrow(PlanRebuildRefused::class, 'No phase covers this week, so your targets were not changed.')
        ->and(DayTarget::query()->count())->toBe(0);
});

it('SET-004 a user without a plan is told the plan has not started', function () {
    $user = User::factory()->create();

    expect(fn () => rebuildRun($user))
        ->toThrow(PlanRebuildRefused::class, 'Your plan has not started yet, so there are no targets to update. Your targets were not changed.');
});
