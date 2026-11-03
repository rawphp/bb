<?php

use App\Actions\Settings\UpdateScheduleDefaults;
use App\Capabilities\Settings\Data\ScheduleDefaultsUpdateInput;
use App\Capabilities\Support\FieldRefused;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeRebuild;

// SET-005 and SCH-039: the Schedule defaults form. Today is Wednesday 14 October 2026; the plan started Monday
// 7 September and reviews on Mondays, so next plan week starts Monday 19 October. Sam trains Thursdays at 17:30.
// The rebuild is FakeRebuild, so these tests show when it runs, not what it writes.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

const SCHEDULE_PHASE = '11111111-1111-4111-8111-111111111111';

function scheduleUser(bool $plan = true, ?string $mesocycleStart = '2026-10-12'): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create([
        'eating_window' => ['start' => '07:00', 'end' => '21:00'], 'meal_count' => 4, 'busy_periods' => [],
        'training_days' => [['weekday' => 4, 'start' => '17:30', 'minutes' => 60]],
        'weigh_in_days' => [['weekday' => 1, 'time' => '07:00']],
        'step_plan' => [],
    ]);
    if (! $plan) {
        return $user;
    }
    Plan::factory()->for($user)->create(['start_date' => '2026-09-07', 'review_weekday' => 1]);
    Phase::factory()->for($user)->create([
        'id' => SCHEDULE_PHASE, 'type' => 'fatLoss', 'start_date' => '2026-09-07', 'end_date' => null, 'target_rate_percent_per_week' => -0.5,
    ]);
    if ($mesocycleStart !== null) {
        Mesocycle::factory()->for($user)->create([
            'phase_id' => SCHEDULE_PHASE, 'name' => 'Meso 1', 'weeks' => 4, 'start_date' => $mesocycleStart,
            'days' => [['id' => 'push', 'label' => 'Push', 'weekday' => 4, 'slots' => [
                ['id' => 'push-chest', 'exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 12],
            ]]],
            'muscle_priorities' => null,
        ]);
    }

    return $user;
}

/** The form as stored, with these fields changed. */
function scheduleInput(array $changes = []): ScheduleDefaultsUpdateInput
{
    return ScheduleDefaultsUpdateInput::validate(array_replace([
        'eating_window_start' => '07:00',
        'eating_window_end' => '21:00',
        'meal_count' => 4,
        'training_days' => [['weekday' => 4, 'start' => '17:30', 'minutes' => 60]],
        'busy_periods' => [],
        'step_plan' => [],
        'review_weekday' => null,
    ], $changes));
}

function updateSchedule(User $user, array $changes = []): array
{
    return app(UpdateScheduleDefaults::class)($user, scheduleInput($changes));
}

function scheduleProfile(User $user): Profile
{
    return Profile::query()->where('user_id', $user->id)->firstOrFail();
}

/** Runs the write and returns the refusal it threw. */
function scheduleRefusal(User $user, array $changes): FieldRefused
{
    try {
        updateSchedule($user, $changes);
    } catch (FieldRefused $refused) {
        return $refused;
    }
    throw new RuntimeException('the write was not refused');
}

it('SET-005 saves the eating window, meal count, training days, busy periods and step plan on the profile and rebuilds', function () {
    $rebuild = FakeRebuild::bind();
    $user = scheduleUser();

    updateSchedule($user, [
        'eating_window_start' => '08:00',
        'eating_window_end' => '01:00',
        'meal_count' => 5,
        'training_days' => [['weekday' => 4, 'start' => '06:30', 'minutes' => 75]],
        'busy_periods' => [['label' => 'Work', 'start' => '09:00', 'end' => '17:00', 'weekdays' => [['weekday' => 3], ['weekday' => 1]]]],
        'step_plan' => [['weekday' => 2, 'min' => 7000, 'max' => 9000], ['weekday' => 1, 'min' => 5000, 'max' => 8000]],
    ]);

    $profile = scheduleProfile($user);
    expect($profile->eating_window)->toEqual(['start' => '08:00', 'end' => '01:00'])
        ->and($profile->meal_count)->toBe(5)
        ->and($profile->training_days)->toEqual([['weekday' => 4, 'start' => '06:30', 'minutes' => 75]])
        ->and($profile->busy_periods)->toEqual([['label' => 'Work', 'start' => '09:00', 'end' => '17:00', 'weekdays' => [1, 3]]])
        ->and($profile->step_plan)->toEqual([['weekday' => 1, 'min' => 5000, 'max' => 8000], ['weekday' => 2, 'min' => 7000, 'max' => 9000]])
        ->and($rebuild->calls)->toHaveCount(1);
});

it('SET-004 saving the schedule as it is stored does not rebuild', function () {
    $rebuild = FakeRebuild::bind();
    $user = scheduleUser();

    updateSchedule($user);

    expect($rebuild->calls)->toBe([]);
});

it('SET-005 training days are kept in weekday order, one session a weekday', function () {
    FakeRebuild::bind();
    $user = scheduleUser(mesocycleStart: null);

    updateSchedule($user, ['training_days' => [
        ['weekday' => 5, 'start' => '07:00', 'minutes' => 75], ['weekday' => 1, 'start' => '17:30', 'minutes' => 60],
    ]]);
    expect(array_column(scheduleProfile($user)->training_days, 'weekday'))->toBe([1, 5]);

    $refused = scheduleRefusal($user, ['training_days' => [
        ['weekday' => 1, 'start' => '07:00', 'minutes' => 60], ['weekday' => 1, 'start' => '18:00', 'minutes' => 60],
    ]]);
    expect($refused->field)->toBe('training_days');
});

it('SET-005 a step plan names each weekday once', function () {
    FakeRebuild::bind();
    $user = scheduleUser();

    $refused = scheduleRefusal($user, ['step_plan' => [
        ['weekday' => 2, 'min' => 7000, 'max' => 9000], ['weekday' => 2, 'min' => 1000, 'max' => 2000],
    ]]);

    expect($refused->field)->toBe('step_plan');
});

it('SET-005 weigh-in days are not part of the schedule defaults and stay as they were', function () {
    FakeRebuild::bind();
    $user = scheduleUser();

    updateSchedule($user, ['meal_count' => 3]);

    expect(scheduleProfile($user)->weigh_in_days)->toEqual([['weekday' => 1, 'time' => '07:00']])
        ->and(array_map(fn (ReflectionParameter $p) => $p->name, (new ReflectionClass(ScheduleDefaultsUpdateInput::class))->getConstructor()->getParameters()))
        ->not->toContain('weigh_in_days');
});

it('SCH-039 a change of training weekdays while a mesocycle is under way is refused on training_days and saves nothing', function () {
    $rebuild = FakeRebuild::bind();
    $user = scheduleUser();

    $refused = scheduleRefusal($user, [
        'eating_window_start' => '09:00',
        'training_days' => [['weekday' => 5, 'start' => '17:30', 'minutes' => 60]],
    ]);

    expect($refused->field)->toBe('training_days')
        ->and($refused->getMessage())->toBe('A mesocycle is under way. Change training weekdays to move its sessions from next week.')
        ->and(scheduleProfile($user)->eating_window)->toEqual(['start' => '07:00', 'end' => '21:00'])
        ->and(scheduleProfile($user)->training_days)->toEqual([['weekday' => 4, 'start' => '17:30', 'minutes' => 60]])
        ->and($rebuild->calls)->toBe([]);
});

it('SCH-039 an added training weekday while a mesocycle is under way is refused too', function () {
    FakeRebuild::bind();
    $user = scheduleUser();

    $refused = scheduleRefusal($user, ['training_days' => [
        ['weekday' => 4, 'start' => '17:30', 'minutes' => 60], ['weekday' => 6, 'start' => '09:00', 'minutes' => 60],
    ]]);

    expect($refused->field)->toBe('training_days');
});

it('SCH-039 the same weekdays with new times or minutes save while a mesocycle is under way, and rebuild', function () {
    $rebuild = FakeRebuild::bind();
    $user = scheduleUser();

    updateSchedule($user, ['training_days' => [['weekday' => 4, 'start' => '06:00', 'minutes' => 90]]]);

    expect(scheduleProfile($user)->training_days)->toEqual([['weekday' => 4, 'start' => '06:00', 'minutes' => 90]])
        ->and($rebuild->calls)->toHaveCount(1);
});

it('SCH-039 the same weekdays sent in another order are not a change of weekdays', function () {
    $rebuild = FakeRebuild::bind();
    $user = scheduleUser();
    scheduleProfile($user)->update(['training_days' => [
        ['weekday' => 1, 'start' => '07:00', 'minutes' => 60], ['weekday' => 4, 'start' => '17:30', 'minutes' => 60],
    ]]);

    updateSchedule($user, ['training_days' => [
        ['weekday' => 4, 'start' => '18:00', 'minutes' => 60], ['weekday' => 1, 'start' => '07:00', 'minutes' => 60],
    ]]);

    expect(array_column(scheduleProfile($user)->training_days, 'start'))->toBe(['07:00', '18:00'])
        ->and($rebuild->calls)->toHaveCount(1);
});

it('SCH-039 with no mesocycle covering next week the weekdays change normally', function (?string $mesocycleStart) {
    $rebuild = FakeRebuild::bind();
    $user = scheduleUser(mesocycleStart: $mesocycleStart);

    updateSchedule($user, ['training_days' => [['weekday' => 5, 'start' => '17:30', 'minutes' => 60]]]);

    expect(scheduleProfile($user)->training_days)->toEqual([['weekday' => 5, 'start' => '17:30', 'minutes' => 60]])
        ->and($rebuild->calls)->toHaveCount(1);
})->with([
    'no mesocycle' => [null],
    'one that ends this week' => ['2026-09-21'],
]);

it('SCH-039 with no plan the weekdays change normally', function () {
    FakeRebuild::bind();
    $user = scheduleUser(plan: false);

    updateSchedule($user, ['training_days' => [['weekday' => 5, 'start' => '17:30', 'minutes' => 60]]]);

    expect(array_column(scheduleProfile($user)->training_days, 'weekday'))->toBe([5]);
});

it('SET-005 a null review day leaves the review day as it is, with or without a plan', function (bool $plan) {
    FakeRebuild::bind();
    $user = scheduleUser(plan: $plan);

    updateSchedule($user, ['meal_count' => 3, 'review_weekday' => null]);

    expect(scheduleProfile($user)->meal_count)->toBe(3)
        ->and(Plan::query()->where('user_id', $user->id)->value('review_weekday'))->toBe($plan ? 1 : null);
})->with(['with a plan' => [true], 'with no plan' => [false]]);

it('SET-005 the plan\'s own review day saves the rest, the day unchanged', function () {
    FakeRebuild::bind();
    $user = scheduleUser();

    updateSchedule($user, ['meal_count' => 3, 'review_weekday' => 1]);

    expect(scheduleProfile($user)->meal_count)->toBe(3)
        ->and(Plan::query()->where('user_id', $user->id)->value('review_weekday'))->toBe(1);
});

it('SET-005 a different review day is refused on review_weekday and nothing is saved', function () {
    $rebuild = FakeRebuild::bind();
    $user = scheduleUser();

    $refused = scheduleRefusal($user, ['meal_count' => 3, 'review_weekday' => 3]);

    expect($refused->field)->toBe('review_weekday')
        ->and($refused->getMessage())->toBe("Changing the review day isn't supported yet.")
        ->and(scheduleProfile($user)->meal_count)->toBe(4)
        ->and(Plan::query()->where('user_id', $user->id)->value('review_weekday'))->toBe(1)
        ->and($rebuild->calls)->toBe([]);
});

it('SET-005 a review day with no plan is refused on review_weekday and nothing is saved', function () {
    FakeRebuild::bind();
    $user = scheduleUser(plan: false);

    $refused = scheduleRefusal($user, ['meal_count' => 3, 'review_weekday' => 1]);

    expect($refused->field)->toBe('review_weekday')
        ->and($refused->getMessage())->toBe('There is no plan yet, so there is no review day to set.')
        ->and(scheduleProfile($user)->meal_count)->toBe(4);
});
