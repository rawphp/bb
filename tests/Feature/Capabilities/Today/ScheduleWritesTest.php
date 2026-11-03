<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The HTTP seam of busy_period_set, schedule_update and training_weekdays_change. The rules are unit tests:
// SetBusyPeriodTest, UpdateScheduleTest and ChangeTrainingWeekdaysTest. schedule_update and training_weekdays_change
// take no row's id, so for them the 404 case is that another user's schedule is never read or changed. Today is
// Tuesday 22 September 2026.

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

const SCHEDULE_BUSY_ID = '01JBX0M1N2P3Q4R5S6T7V8W9C1';

function schedulePost(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/** @return array<string, array<string, mixed>> a valid input for each capability */
function scheduleInputs(): array
{
    return [
        'busy_period_set' => ['id' => SCHEDULE_BUSY_ID, 'label' => 'School run', 'start' => '15:00', 'end' => '15:45', 'weekdays' => [3, 5]],
        'schedule_update' => [
            'date' => '2026-09-23', 'apply_to' => 'all_future_days', 'window_start' => '07:00', 'window_end' => '21:00',
            'meal_count' => 3, 'busy_periods' => [], 'training_start' => null,
        ],
        'training_weekdays_change' => ['weekdays' => [2, 5], 'fewer_days' => null],
    ];
}

$writes = [
    'busy_period_set' => [['busy-period', 'set'], false, false],
    'schedule_update' => [['schedule', 'update'], true, false],
    'training_weekdays_change' => [['training-weekdays', 'change'], true, true],
];

it('SCH-033 SCH-037 SCH-039 registers each schedule write on every host surface, affecting Today and the week strip', function (string $name, array $cli, bool $reversible, bool $destructive) {
    $definition = app(CapabilityRegistry::class)->get($name);

    expect($definition->surfaces)->toEqualCanonicalizing(HostDefinition::SURFACES)
        ->and($definition->readOnly)->toBeFalse()
        ->and(HostDefinition::affects($definition))->toContain('today_show', 'week_show')
        ->and(HostDefinition::isReversible($definition))->toBe($reversible)
        ->and(HostDefinition::isDestructive($definition))->toBe($destructive)
        ->and([$definition->cliDomain, $definition->cliVerb])->toBe($cli)
        ->and($definition->description)->not->toBe('');
})->with(array_map(fn (string $name, array $row) => [$name, ...$row], array_keys($writes), $writes));

it('SCH-033 SCH-037 SCH-039 refuses each schedule write when signed out', function (string $name) {
    schedulePost(null, $name, scheduleInputs()[$name])->assertUnauthorized();
})->with(array_keys($writes));

it('SCH-033 CAP-019 busy_period_set answers 404 for an id another user\'s busy period holds, and changes neither profile', function () {
    $them = TrainSeed::user();
    Profile::query()->where('user_id', $them->id)->update(['busy_periods' => json_encode([
        ['id' => strtolower(SCHEDULE_BUSY_ID), 'label' => 'Gym run', 'start' => '06:00', 'end' => '07:00', 'weekdays' => [1]],
    ])]);
    $user = TrainSeed::user();
    $mine = Profile::query()->where('user_id', $user->id)->sole()->busy_periods;

    schedulePost($user, 'busy_period_set', scheduleInputs()['busy_period_set'])->assertNotFound();

    expect(Profile::query()->where('user_id', $them->id)->sole()->busy_periods[0]['label'])->toBe('Gym run')
        ->and(Profile::query()->where('user_id', $user->id)->sole()->busy_periods)->toEqual($mine);
});

it('SCH-037 SCH-039 schedule_update and training_weekdays_change change only the caller\'s schedule', function (string $name) {
    $them = TrainSeed::user();
    $theirs = Profile::query()->where('user_id', $them->id)->sole()->only(['eating_window', 'meal_count', 'training_days']);
    $user = TrainSeed::user();

    schedulePost($user, $name, scheduleInputs()[$name])->assertOk();

    expect(Profile::query()->where('user_id', $them->id)->sole()->only(['eating_window', 'meal_count', 'training_days']))->toEqual($theirs);
})->with(['schedule_update', 'training_weekdays_change']);

it('SCH-039 SCH-056 CAP-013 training_weekdays_change re-targets today with a toast, and undo puts the weekdays and today back', function () {
    $user = TrainSeed::user();
    $today = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest', 'kcal' => 2200.0]);
    $before = [Profile::query()->where('user_id', $user->id)->sole()->training_days, $today->fresh()->meals];

    $response = schedulePost($user, 'training_weekdays_change', ['weekdays' => [2, 5], 'fewer_days' => null])
        ->assertOk()->assertJsonPath('data.toast', 'Meals updated for your new schedule');
    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-22"}', 'week_show{"date":"2026-09-22"}');

    schedulePost($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect([Profile::query()->where('user_id', $user->id)->sole()->training_days, $today->fresh()->meals])->toEqual($before);
});

it('SCH-039 CAP-013 undo of a restructure puts the mesocycle back and removes the pinned rows', function () {
    $user = TrainSeed::user();
    TrainSeed::phase($user, 'muscleGain', '2026-08-31');
    $mesocycle = TrainSeed::mesocycle($user);
    $before = $mesocycle->fresh()->only(['days', 'weeks', 'earlier_weeks', 'later_weeks', 'days_per_week']);

    $response = schedulePost($user, 'training_weekdays_change', ['weekdays' => [3], 'fewer_days' => 'restructure'])->assertOk();
    expect($mesocycle->fresh()->days)->toHaveCount(1)
        ->and(WorkoutSession::query()->count())->toBe(2)
        ->and($response->json('data.invalidates'))->toContain('workout_show{"day":1,"mesocycle_id":"'.$mesocycle->id.'","week":4}');

    schedulePost($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect($mesocycle->fresh()->only(['days', 'weeks', 'earlier_weeks', 'later_weeks', 'days_per_week']))->toEqual($before)
        ->and(WorkoutSession::query()->count())->toBe(0);
});

it('SCH-033 SCH-037 SCH-039 answers 422 naming the bad field', function (string $name, array $bad, string $field) {
    $user = TrainSeed::user();

    schedulePost($user, $name, array_replace(scheduleInputs()[$name], $bad))->assertStatus(422)->assertJsonPath('error.violations.0.field', $field);
})->with([
    'busy_period_set, a start that is not a time' => ['busy_period_set', ['start' => '3 PM'], 'start'],
    'schedule_update, a meal count out of range' => ['schedule_update', ['meal_count' => 9], 'meal_count'],
    'training_weekdays_change, a weekday out of range' => ['training_weekdays_change', ['weekdays' => [8]], 'weekdays.0'],
]);
