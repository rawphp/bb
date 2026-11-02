<?php

use App\Actions\Today\DeleteOtherActivity;
use App\Actions\Today\RetargetableDay;
use App\Actions\Today\SaveOtherActivity;
use App\Capabilities\Today\Data\OtherActivitySaveInput;
use App\Models\Mesocycle;
use App\Models\OtherActivity;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Today\DayPlans;
use App\Support\Today\TodaySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// What other_activity_save and other_activity_delete save (SCH-032, SCH-038, NUT-003, NUT-005), ported from rp-clone's
// TodayAddWorkoutTests. The rule is RetargetTest's (otherActivity). The user weighs 64 kg, so 45 minutes at Moderate is
// 0.75 h x 64 kg x 5 = 240 kcal, all of it carbs. Wednesday 23 September 2026 holds no session; Thursday 24 holds
// Lower A at 5:30 PM.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

const ACTIVITY = '01JBX0A1B2C3D4E5F6G7H8J9K0';

function saveActivity(User $user, array $values = []): array
{
    $values = array_replace(['id' => ACTIVITY, 'date' => '2026-09-23', 'name' => 'Run', 'start' => '07:00', 'minutes' => 45, 'intensity' => 'moderate'], $values);
    $existing = OtherActivity::query()->find($values['id']);

    return app(SaveOtherActivity::class)($user, $existing, new OtherActivitySaveInput(...$values));
}

function activityWorld(): User
{
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);

    return $user;
}

it('SCH-032 NUT-005 saving an Other activity stores it with the intensity picked and adds its energy to the day, as carbs', function () {
    $user = activityWorld();
    $day = SheetSeed::day($user);

    $saved = saveActivity($user);

    $row = OtherActivity::query()->sole();
    $written = $day->fresh();
    expect($saved['toast'])->toBe('Meals updated for Run')
        ->and($saved['dates'])->toBe(['2026-09-23'])
        ->and($saved['week'])->toHaveCount(7)
        ->and($row->only(['user_id', 'name', 'start_time', 'minutes', 'intensity']))->toBe(['user_id' => $user->id, 'name' => 'Run', 'start_time' => '07:00', 'minutes' => 45, 'intensity' => 'moderate'])
        ->and(strtolower($row->id))->toBe(strtolower(ACTIVITY))
        ->and($row->date->format('Y-m-d'))->toBe('2026-09-23')
        ->and($written->kcal)->toEqualWithDelta(2600.0 + 240.0, 1e-6)
        ->and($written->carbs_g)->toEqualWithDelta(320.0 + 60.0, 1e-6)
        ->and($written->protein_g)->toBe(170.0)
        ->and($written->fat_g)->toBe(70.0)
        ->and($written->day_type->value)->toBe('moderate')
        // Nothing is placed again: the start time is for the timeline only.
        ->and(collect($written->meals)->pluck('time')->all())->toBe(['07:30', '12:30', '19:00']);
});

it('NUT-005 a hard activity makes the day hard', function () {
    $user = activityWorld();
    $day = SheetSeed::day($user);

    saveActivity($user, ['intensity' => 'hard']);

    expect($day->fresh()->day_type->value)->toBe('hard')
        ->and(OtherActivity::query()->sole()->intensity)->toBe('hard');
});

it('SCH-038 an activity on a training day shares it with the session, and the meals stay placed around the session only', function () {
    $user = activityWorld();
    $day = SheetSeed::day($user, '2026-09-24');
    $lunch = SheetSeed::id(SheetSeed::LUNCH, '260924');
    SheetSeed::checkIn($lunch);
    $lunchBefore = collect($day->meals)->firstWhere('id', $lunch);

    saveActivity($user, ['date' => '2026-09-24', 'start' => '07:00', 'intensity' => 'hard']);

    $written = $day->fresh();
    expect($written->day_type->value)->toBe('hard')
        ->and(collect($written->meals)->pluck('time')->all())->toBe(['07:30', '12:30', '19:00'])
        ->and(collect($written->meals)->firstWhere('id', $lunch))->toEqual($lunchBefore)
        ->and(TodaySnapshot::load($user, '2026-09-24')->workout(DayPlans::day('2026-09-24'))?->time->shortText())->toBe('5:30 PM');
});

it('SCH-032 saving the stored activity again edits it, and only the change in energy goes to the day', function () {
    $user = activityWorld();
    $day = SheetSeed::day($user);
    saveActivity($user);

    saveActivity($user, ['name' => 'Run + football', 'minutes' => 90]);

    expect(OtherActivity::query()->sole()->only(['name', 'minutes']))->toBe(['name' => 'Run + football', 'minutes' => 90])
        // 45 more minutes at Moderate.
        ->and($day->fresh()->kcal)->toEqualWithDelta(2600.0 + 480.0, 1e-6);
});

it('SCH-032 SCH-038 refuses a blank name, an impossible length, a second activity on the day and a move to another day', function (array $first, array $values, string $message) {
    $user = activityWorld();
    $day = SheetSeed::day($user);
    SheetSeed::day($user, '2026-09-25');
    if ($first !== []) {
        saveActivity($user, $first);
    }
    $rows = OtherActivity::query()->get()->toArray();
    $before = $day->fresh()->only(['kcal', 'meals']);

    expect(fn () => saveActivity($user, $values))->toThrow(DomainException::class, $message)
        ->and(OtherActivity::query()->get()->toArray())->toEqual($rows)
        ->and($day->fresh()->only(['kcal', 'meals']))->toEqual($before);
})->with([
    'a blank name' => [[], ['name' => '  '], SaveOtherActivity::NO_NAME],
    'no minutes' => [[], ['minutes' => 0], SaveOtherActivity::LENGTH],
    'over a day' => [[], ['minutes' => 1441], SaveOtherActivity::LENGTH],
    'a second activity' => [['id' => '01JBX0A1B2C3D4E5F6G7H8J9K1'], [], SaveOtherActivity::ONE_A_DAY],
    'onto another day' => [['date' => '2026-09-25'], [], SaveOtherActivity::STAYS],
]);

it('SCH-032 refuses an activity on a past day', function () {
    $user = activityWorld();
    SheetSeed::day($user, '2026-09-21');

    expect(fn () => saveActivity($user, ['date' => '2026-09-21']))->toThrow(DomainException::class, RetargetableDay::PAST)
        ->and(OtherActivity::query()->count())->toBe(0);
});

it('SCH-032 deleting the activity removes it and takes its energy back out of the day', function () {
    $user = activityWorld();
    $day = SheetSeed::day($user, '2026-09-23', ['day_type' => 'rest']);
    saveActivity($user);

    $deleted = app(DeleteOtherActivity::class)($user, ACTIVITY);

    expect($deleted['toast'])->toBe('Meals updated: Run removed')
        ->and($deleted['dates'])->toBe(['2026-09-23'])
        ->and(OtherActivity::query()->count())->toBe(0)
        ->and($day->fresh()->kcal)->toEqualWithDelta(2600.0, 1e-6)
        ->and($day->fresh()->day_type->value)->toBe('rest');
});

it('SCH-032 a past day\'s activity is not deleted', function () {
    $user = activityWorld();
    SheetSeed::day($user, '2026-09-21');
    $row = OtherActivity::factory()->for($user)->create(['date' => '2026-09-21', 'name' => 'Swim', 'start_time' => '07:00', 'minutes' => 30, 'intensity' => 'light']);

    expect(fn () => app(DeleteOtherActivity::class)($user, $row->id))->toThrow(DomainException::class, RetargetableDay::PAST)
        ->and(OtherActivity::query()->count())->toBe(1);
});

it('SCH-038 SCH-053 a skipped session\'s energy is not on its day: the activity alone sets the day type', function () {
    $user = activityWorld();
    $mesocycle = Mesocycle::query()->sole();
    WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'is_skipped' => true,
        'planned_start_at' => '2026-09-24 17:30:00', 'planned_end_at' => '2026-09-24 18:30:00',
    ]);
    $day = SheetSeed::day($user, '2026-09-24', ['day_type' => 'rest']);

    saveActivity($user, ['date' => '2026-09-24', 'intensity' => 'light']);

    // 0.75 h x 64 kg x 3.
    expect($day->fresh()->day_type->value)->toBe('light')
        ->and($day->fresh()->kcal)->toEqualWithDelta(2600.0 + 144.0, 1e-6);
});

it('SCH-038 TBL-031 a session in the deload week is Light, so a light activity leaves the day light', function () {
    $user = TrainSeed::user();
    // Twelve working sets on Thursday: Moderate in a normal week, Light in the deload week (week 5).
    $mesocycle = TrainSeed::mesocycle($user);
    $days = $mesocycle->days;
    $days[1]['slots'][0]['sets'] = 12;
    $mesocycle->update(['days' => $days]);
    $normal = SheetSeed::day($user, '2026-09-24');
    $deload = SheetSeed::day($user, '2026-10-08');

    saveActivity($user, ['date' => '2026-09-24', 'intensity' => 'light']);
    saveActivity($user, ['id' => '01JBX0A1B2C3D4E5F6G7H8J9K1', 'date' => '2026-10-08', 'intensity' => 'light']);

    expect($normal->fresh()->day_type->value)->toBe('moderate')
        ->and($deload->fresh()->day_type->value)->toBe('light');
});

it('SCH-032 a save or a delete whose activity row fails leaves the day as it was', function (string $write) {
    $user = activityWorld();
    $day = SheetSeed::day($user);
    if ($write === 'delete') {
        saveActivity($user);
    }
    $before = $day->fresh()->only(['day_type', 'kcal', 'meals']);
    OtherActivity::saving(fn (): never => throw new RuntimeException('the activity could not be saved'));
    OtherActivity::deleting(fn (): never => throw new RuntimeException('the activity could not be saved'));

    expect(fn () => $write === 'save' ? saveActivity($user) : app(DeleteOtherActivity::class)($user, ACTIVITY))
        ->toThrow(RuntimeException::class, 'the activity could not be saved')
        ->and($day->fresh()->only(['day_type', 'kcal', 'meals']))->toEqual($before);
})->with(['save', 'delete']);
