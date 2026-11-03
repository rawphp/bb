<?php

use App\Actions\Plan\RebuildTargets;
use App\Domain\Model\DayType;
use App\Domain\Plan\PlanRebuildRefusal\Reason;
use App\Domain\Plan\PlanRebuildRefused;
use App\Domain\WeekBuilder\MealNames;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    $user = rebuildUser(['training_only' => true], phase: false);

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

/**
 * Stored meals with the given ids, as a day_targets row keeps them.
 *
 * @param  list<string>  $ids
 * @return list<array<string, mixed>>
 */
function rebuildRowMeals(array $ids): array
{
    return array_map(fn (string $id, int $i) => [
        'id' => $id, 'name' => 'Meal '.($i + 1), 'time' => sprintf('%02d:00', 8 + $i * 3), 'kcal' => 500, 'protein_g' => 30, 'fat_g' => 15, 'carbs_g' => 60,
    ], $ids, array_keys($ids));
}

/** @return array{kcal: float, meals: list<array<string, mixed>>, index: list<string>} a row's targets, meals and meal index */
function rebuildRowState(DayTarget $row): array
{
    $row->refresh();

    return [
        'kcal' => $row->kcal,
        'meals' => $row->meals,
        'index' => DB::table('day_target_meals')->where('day_target_id', $row->id)->orderBy('position')->pluck('id')->all(),
    ];
}

it('SET-004 a refused rebuild leaves the stored days after today, their meals and their meal index as they were', function () {
    $user = rebuildUser();
    $rows = [];
    foreach (['2026-10-15', '2026-10-16'] as $date) {
        $rows[$date] = DayTarget::factory()->for($user)->create([
            'date' => $date, 'meals' => rebuildRowMeals(array_map(fn () => strtolower((string) Str::uuid7()), range(1, 4))),
        ]);
    }
    $before = array_map(fn (DayTarget $row) => rebuildRowState($row), $rows);

    // 2000 kcal a day below the recommendation takes carbs and fat below their floors (SAFE-012).
    expect(fn () => rebuildRun($user, -2000))->toThrow(PlanRebuildRefused::class, 'These details would take your carbs or fat below the safe minimum. Your targets were not changed.')
        ->and(array_map(fn (DayTarget $row) => rebuildRowState($row), $rows))->toBe($before)
        ->and(DayTarget::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('SET-004 a save that fails partway through the week writes none of the week', function () {
    $user = rebuildUser();
    // Another user's day already holds this meal id, so saving the 17th, which still lists it, is refused.
    $taken = strtolower((string) Str::uuid7());
    DayTarget::factory()->create(['date' => '2026-10-17', 'meals' => rebuildRowMeals([$taken])]);
    $broken = (string) Str::uuid7();
    DB::table('day_targets')->insert([
        'id' => $broken, 'user_id' => $user->id, 'date' => '2026-10-17', 'day_type' => 'rest', 'kcal' => 2000, 'protein_g' => 140,
        'fat_g' => 60, 'carbs_g' => 220, 'meals' => json_encode(rebuildRowMeals([$taken])), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => rebuildRun($user))->toThrow(ValidationException::class)
        ->and(DayTarget::query()->where('user_id', $user->id)->pluck('id')->all())->toBe([$broken])
        ->and(DayTarget::query()->findOrFail($broken)->kcal)->toBe(2000.0);
});

it('SET-004 of two stored rows for one date, the older is rebuilt in place and the newer is left as it was', function () {
    $user = rebuildUser();
    $olderIds = array_map(fn () => strtolower((string) Str::uuid7()), range(1, 4));
    $newerIds = array_map(fn () => strtolower((string) Str::uuid7()), range(1, 4));
    $older = DayTarget::factory()->for($user)->create(['date' => '2026-10-16', 'meals' => rebuildRowMeals($olderIds), 'created_at' => '2026-10-01 08:00:00']);
    $newer = DayTarget::factory()->for($user)->create(['date' => '2026-10-16', 'meals' => rebuildRowMeals($newerIds), 'created_at' => '2026-10-02 08:00:00']);
    $newerBefore = rebuildRowState($newer);

    expect(rebuildRun($user))->toContain('2026-10-16');

    $olderAfter = rebuildRowState($older);
    expect(DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-10-16')->count())->toBe(2)
        ->and(array_column($olderAfter['meals'], 'id'))->toBe($olderIds)
        ->and($olderAfter['index'])->toBe($olderIds)
        ->and($olderAfter['kcal'])->not->toBe(2000.0)
        ->and(rebuildRowState($newer))->toBe($newerBefore);
});

it('SET-004 a user without a plan is told the plan has not started', function () {
    $user = User::factory()->create();

    expect(fn () => rebuildRun($user))
        ->toThrow(PlanRebuildRefused::class, 'Your plan has not started yet, so there are no targets to update. Your targets were not changed.');
});

/** @return array<string, list<string>> each written day's meal times by Y-m-d */
function rebuildMealTimesByDay(User $user): array
{
    return rebuildRows($user)->map(fn (DayTarget $row) => array_column($row->meals, 'time'))->all();
}

it('SET-005 a weekday busy period moves only its weekdays\' meals', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $plainTimes = rebuildMealTimesByDay($user);

    $user->profile->update(['busy_periods' => [['start' => '11:00', 'end' => '13:00', 'weekdays' => [1, 2, 3, 4, 5]]]]);
    rebuildRun($user);
    $busyTimes = rebuildMealTimesByDay($user);

    // Friday the 16th is busy; Saturday the 17th and Sunday the 18th are untouched.
    expect($busyTimes['2026-10-16'])->not->toBe($plainTimes['2026-10-16'])
        ->and($busyTimes['2026-10-17'])->toBe($plainTimes['2026-10-17'])
        ->and($busyTimes['2026-10-18'])->toBe($plainTimes['2026-10-18']);
});

it('SET-005 a Saturday busy period moves Saturday only', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $plainTimes = rebuildMealTimesByDay($user);

    $user->profile->update(['busy_periods' => [['start' => '11:00', 'end' => '13:00', 'weekdays' => [6]]]]);
    rebuildRun($user);
    $busyTimes = rebuildMealTimesByDay($user);

    expect($busyTimes['2026-10-17'])->not->toBe($plainTimes['2026-10-17'])
        ->and($busyTimes['2026-10-16'])->toBe($plainTimes['2026-10-16'])
        ->and($busyTimes['2026-10-18'])->toBe($plainTimes['2026-10-18']);
});

it('SET-005 a busy period with no weekdays applies every day', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $plainTimes = rebuildMealTimesByDay($user);

    $user->profile->update(['busy_periods' => [['start' => '11:00', 'end' => '13:00']]]);
    rebuildRun($user);
    $busyTimes = rebuildMealTimesByDay($user);

    expect($busyTimes['2026-10-16'])->not->toBe($plainTimes['2026-10-16'])
        ->and($busyTimes['2026-10-17'])->not->toBe($plainTimes['2026-10-17']);
});

it('NUT-030 stored meals keep their ids in the stored order whatever their times say', function () {
    $user = rebuildUser();
    $ids = ['aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-000000000003', 'aaaaaaaa-0000-4000-8000-000000000004'];
    $meals = array_map(fn (string $id, string $time) => [
        'id' => $id, 'name' => 'M', 'time' => $time, 'kcal' => 500, 'protein_g' => 30, 'fat_g' => 15, 'carbs_g' => 60,
    ], $ids, ['16:00', '08:00', '20:00', '12:00']);
    DayTarget::factory()->for($user)->create(['date' => '2026-10-15', 'meals' => $meals]);

    rebuildRun($user);

    expect(array_column(rebuildRows($user)['2026-10-15']->meals, 'id'))->toBe($ids);
});

it('SAFE-017 before the plan starts the first week can be built whole', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
    $user = rebuildUser();

    $written = rebuildRun($user, beforeStart: true);

    expect($written)->toBe(['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13'])
        ->and(DayTarget::query()->where('user_id', $user->id)->count())->toBe(7);
});

it('SAFE-017 before the plan starts the rebuild is refused without the flag and writes nothing', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
    $user = rebuildUser();

    try {
        rebuildRun($user);
        $reason = null;
    } catch (PlanRebuildRefused $refused) {
        $reason = $refused->refusal->reason;
    }

    expect($reason)->toBe(Reason::PlanNotStarted)
        ->and(DayTarget::query()->count())->toBe(0);
});

it('SAFE-017 a calorie change moves every written day', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $plain = rebuildKcal($user);

    rebuildRun($user, -200);

    foreach (rebuildKcal($user) as $date => $kcal) {
        expect($kcal)->toEqualWithDelta($plain[$date] - 200, 5);
    }
});

it('SCH-060 an away week that pauses training and holds maintenance has only rest days above the deficit', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user);
    rebuildRun($user);
    $plain = rebuildKcal($user);

    PlanWeek::factory()->for($user)->create([
        'start_date' => '2026-10-12', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance',
    ]);
    rebuildRun($user);

    expect(rebuildTypes($user))->each->toBe(DayType::Rest)
        ->and(rebuildKcal($user)['2026-10-16'])->toBeGreaterThan($plain['2026-10-16']);
});

it('SCH-060 an away week on rest days leaves the workout energy out and keeps the deficit', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user);
    rebuildRun($user);
    $plain = rebuildKcal($user);
    expect(rebuildTypes($user)['2026-10-15'])->not->toBe(DayType::Rest);

    $week = PlanWeek::factory()->for($user)->create([
        'start_date' => '2026-10-12', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance',
    ]);
    rebuildRun($user);
    $maintenance = rebuildKcal($user);

    $week->update(['away_calories' => 'rest_days']);
    rebuildRun($user);
    $restDays = rebuildKcal($user);

    // Training is paused, so there is no workout to leave out on Friday: its deficit is the plain week's, below maintenance.
    // Thursday's session is not planned either, so it is as Friday: the plain week's workout energy is gone.
    expect(rebuildTypes($user))->each->toBe(DayType::Rest)
        ->and($restDays['2026-10-16'])->toEqualWithDelta($plain['2026-10-16'], 1e-6)
        ->and($restDays['2026-10-16'])->toBeLessThan($maintenance['2026-10-16'])
        ->and($restDays['2026-10-15'])->toBeLessThan($plain['2026-10-15']);
});

it('SCH-060 an away week that deloads still runs its session, and rest-day calories leave its workout energy out', function () {
    $user = rebuildUser();
    rebuildSeedMesocycle($user);

    // A deloaded week runs at maintenance (PLAN-022), so the two runs differ only by the session's energy.
    $week = PlanWeek::factory()->for($user)->create([
        'start_date' => '2026-10-12', 'is_away' => true, 'away_training' => 'deload', 'away_calories' => 'maintenance',
    ]);
    rebuildRun($user);
    $maintenance = rebuildKcal($user);

    $week->update(['away_calories' => 'rest_days']);
    rebuildRun($user);

    $types = rebuildTypes($user);
    expect($types['2026-10-15'])->not->toBe(DayType::Rest)
        ->and($types['2026-10-16'])->toBe(DayType::Rest)
        ->and(rebuildKcal($user)['2026-10-16'])->toEqualWithDelta($maintenance['2026-10-16'], 1e-6)
        ->and(rebuildKcal($user)['2026-10-15'])->toBeLessThan($maintenance['2026-10-15']);
});

it('SCH-060 an earlier week that paused training does not count as a mesocycle week', function () {
    // A 3-week mesocycle from 2026-09-28 covers weeks 28 Sep, 5 Oct and 12 Oct, so 12 Oct is its deload week. With the
    // week of 5 Oct paused it covers 28 Sep, 12 Oct and 19 Oct, and 12 Oct is only its second week: no deload.
    $user = rebuildUser();
    rebuildSeedMesocycle($user, ['start_date' => '2026-09-28', 'weeks' => 3]);
    rebuildRun($user);
    $deload = rebuildKcal($user);

    PlanWeek::factory()->for($user)->create(['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause']);
    rebuildRun($user);
    $shifted = rebuildKcal($user);

    // A deload week runs at maintenance (PLAN-022), so its kcal is above the same week's deficit.
    expect($shifted['2026-10-16'])->toBeLessThan($deload['2026-10-16']);
});

it('NUT-030 a stored intra-workout shake keeps its id, and the regular meals theirs', function () {
    // 22 sets is 65 minutes, over the 60 that earn a shake (SCH-043).
    $user = rebuildUser();
    rebuildSeedMesocycle($user, ['days' => [['id' => 'push', 'label' => 'Push', 'weekday' => 4, 'slots' => [
        ['id' => 'push-chest', 'exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 22],
    ]]]]);
    $regular = array_map(fn (int $n) => sprintf('bbbbbbbb-0000-4000-8000-00000000000%d', $n), [1, 2, 3, 4]);
    $shakeId = 'cccccccc-0000-4000-8000-000000000001';
    $meal = fn (string $id, string $name) => [
        'id' => $id, 'name' => $name, 'time' => '12:00', 'kcal' => 500, 'protein_g' => 30, 'fat_g' => 15, 'carbs_g' => 60,
    ];
    $stored = DayTarget::factory()->for($user)->create(['date' => '2026-10-15', 'meals' => [
        ...array_map(fn (string $id) => $meal($id, 'M'), $regular),
        $meal($shakeId, MealNames::INTRA_WORKOUT_SHAKE),
    ]]);

    rebuildRun($user);

    $meals = rebuildRows($user)['2026-10-15']->meals;
    $shake = array_values(array_filter($meals, fn (array $m) => $m['name'] === MealNames::INTRA_WORKOUT_SHAKE));
    $others = array_values(array_filter($meals, fn (array $m) => $m['name'] !== MealNames::INTRA_WORKOUT_SHAKE));
    expect($meals)->toHaveCount(5)
        ->and($shake)->toHaveCount(1)
        ->and($shake[0]['id'])->toBe($shakeId)
        ->and(array_column($others, 'id'))->toBe($regular)
        ->and(DB::table('day_target_meals')->where('day_target_id', $stored->id)->where('id', $shakeId)->exists())->toBeTrue();
});
it('PLAN-023 turning the diet break off keeps a deload week in the deficit', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $deficit = rebuildKcal($user);

    $week = PlanWeek::factory()->for($user)->create(['start_date' => '2026-10-12', 'is_deload' => true]);
    rebuildRun($user);
    $deload = rebuildKcal($user);

    $week->update(['diet_break_off' => true]);
    rebuildRun($user);

    expect($deload['2026-10-15'])->toBeGreaterThan($deficit['2026-10-15'])
        ->and(rebuildKcal($user)['2026-10-15'])->toEqualWithDelta($deficit['2026-10-15'], 1e-6);
});

it('MESO-043 an ended mesocycle plans no session after its last day, and its week is no deload', function () {
    $user = rebuildUser();
    rebuildRun($user);
    $plain = rebuildKcal($user);
    rebuildSeedMesocycle($user, ['ended_on' => '2026-10-14']);

    rebuildRun($user);

    // It keeps its stored four weeks: read as the one week it ran, this week would be its deload, at maintenance.
    expect(rebuildTypes($user)['2026-10-15'])->toBe(DayType::Rest)
        ->and(rebuildKcal($user)['2026-10-16'])->toEqualWithDelta($plain['2026-10-16'], 1e-6);
});

it('SCH-038 the first Other activity of a day sets its day type', function () {
    $user = rebuildUser();
    OtherActivity::factory()->for($user)->create([
        'date' => '2026-10-16', 'minutes' => 60, 'intensity' => 'moderate', 'created_at' => '2026-10-14 08:00:00',
    ]);
    OtherActivity::factory()->for($user)->create([
        'date' => '2026-10-16', 'minutes' => 60, 'intensity' => 'hard', 'created_at' => '2026-10-14 09:00:00',
    ]);

    rebuildRun($user);

    expect(rebuildTypes($user)['2026-10-16'])->toBe(DayType::Moderate);
});
