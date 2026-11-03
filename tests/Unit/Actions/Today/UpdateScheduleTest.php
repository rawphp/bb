<?php

use App\Actions\Today\RetargetableDay;
use App\Actions\Today\UpdateSchedule;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\ScheduleUpdateInput;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeRebuild;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// SCH-037: Edit schedule. "This day" places the day's unchecked meals again with the edited window, meal count and
// busy periods, and stores none of them; "all future days" saves them on the profile for the day's weekday and
// rebuilds the days after today (SET-004; FakeRebuild here, so these tests show that it runs, not what it writes).
// Meso 2 trains Upper A on Monday and Lower A on Thursday at 5:30 PM for 60 minutes; today is Tuesday 22 September
// 2026, in the plan week of Monday 21 to Sunday 27. The seeded day has Breakfast 7:30, Lunch 12:30, Dinner 19:00.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Mesocycle} */
function editWorld(): array
{
    Carbon::setTestNow('2026-09-22 08:00:00');
    $user = TrainSeed::user();
    Profile::query()->where('user_id', $user->id)->update([
        'eating_window' => json_encode(['start' => '07:00', 'end' => '21:00']),
        'meal_count' => 3,
        'busy_periods' => json_encode([
            ['label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2, 3, 4, 5]],
            ['label' => 'Choir', 'start' => '18:00', 'end' => '19:00', 'weekdays' => [3, 5]],
        ]),
    ]);

    return [$user, TrainSeed::mesocycle($user)];
}

function editSchedule(User $user, array $values = []): array
{
    return app(UpdateSchedule::class)($user, ScheduleUpdateInput::validate(array_replace([
        'date' => '2026-09-23', 'apply_to' => 'this_day', 'window_start' => '07:00', 'window_end' => '21:00',
        'meal_count' => 3, 'busy_periods' => [], 'training_start' => null,
    ], $values)));
}

function editProfile(User $user): Profile
{
    return Profile::query()->where('user_id', $user->id)->sole();
}

/** @return list<int> each meal's time as minutes of the day, in stored order */
function mealMinutes(DayTarget $day): array
{
    return array_map(function (array $meal) {
        [$hour, $minute] = array_map(intval(...), explode(':', $meal['time']));

        return $hour * 60 + $minute;
    }, $day->fresh()->meals);
}

// MARK: this day

it('SCH-037 this day places the unchecked meals inside the edited window and outside the edited busy periods, and stores no settings', function () {
    [$user] = editWorld();
    $day = SheetSeed::day($user, '2026-09-23', ['day_type' => 'rest', 'kcal' => 2200.0]);
    $before = editProfile($user)->only(['eating_window', 'meal_count', 'busy_periods', 'training_days']);

    $edited = editSchedule($user, ['window_start' => '08:00', 'window_end' => '20:00', 'busy_periods' => [['label' => 'Dentist', 'start' => '11:00', 'end' => '14:00']]]);

    expect($edited['toast'])->toBe(UpdateSchedule::RETARGETED)
        ->and($edited['dates'])->toBe(['2026-09-23'])
        ->and(mealMinutes($day))->toHaveCount(3)
        ->and(editProfile($user)->only(['eating_window', 'meal_count', 'busy_periods', 'training_days']))->toEqual($before);
    foreach (mealMinutes($day) as $minute) {
        expect($minute)->toBeGreaterThanOrEqual(8 * 60)->toBeLessThanOrEqual(20 * 60)
            ->and($minute >= 11 * 60 && $minute < 14 * 60)->toBeFalse();
    }
});

it('SCH-037 this day with more meals adds them, and the day\'s total is shared over every meal', function () {
    [$user] = editWorld();
    $day = SheetSeed::day($user, '2026-09-23', ['day_type' => 'rest', 'kcal' => 2200.0]);

    editSchedule($user, ['meal_count' => 5]);

    $meals = $day->fresh()->meals;
    expect($meals)->toHaveCount(5)
        ->and(array_column($meals, 'id'))->toContain(SheetSeed::BREAKFAST, SheetSeed::LUNCH, SheetSeed::DINNER)
        ->and(collect($meals)->sum('kcal'))->toEqualWithDelta($day->fresh()->kcal, 1.0);
});

it('SCH-037 SCH-055 this day with fewer meals takes off the last unchecked ones, never a checked-in meal, which keeps its target', function () {
    [$user] = editWorld();
    $day = SheetSeed::day($user, '2026-09-23', ['day_type' => 'rest', 'kcal' => 2200.0]);
    SheetSeed::checkIn(SheetSeed::DINNER);
    $dinner = collect($day->meals)->firstWhere('id', SheetSeed::DINNER);

    editSchedule($user, ['meal_count' => 2]);

    $meals = collect($day->fresh()->meals);
    expect($meals->pluck('id')->all())->toBe([SheetSeed::BREAKFAST, SheetSeed::DINNER])
        ->and($meals->firstWhere('id', SheetSeed::DINNER)['kcal'])->toEqual($dinner['kcal']);
});

it('SCH-037 this day with a new training time moves the session\'s row as workout_time_set does, and places the meals around it', function () {
    [$user, $mesocycle] = editWorld();
    $day = SheetSeed::day($user, '2026-09-24');

    $edited = editSchedule($user, ['date' => '2026-09-24', 'training_start' => '12:00']);

    $row = WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->sole();
    expect([$row->week, $row->day])->toBe([3, 2])
        ->and($row->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-24 12:00')
        ->and($edited['toast'])->toBe('Meals updated for your 12:00 PM workout')
        ->and($edited['dates'])->toBe(['2026-09-24'])
        // SCH-042: a post-workout meal starts within an hour of the 1 PM end, not around the old 5:30 PM.
        ->and(array_filter(mealMinutes($day), fn (int $minute) => $minute >= 13 * 60 && $minute <= 14 * 60))->not->toBeEmpty();
});

it('SCH-038 this day keeps the time of a session already started, and still places the meals', function () {
    [$user, $mesocycle] = editWorld();
    SheetSeed::day($user, '2026-09-24');
    $started = WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'actual_start_at' => '2026-09-24 07:00:00']);

    $edited = editSchedule($user, ['date' => '2026-09-24', 'training_start' => '12:00']);

    expect($edited['toast'])->toBe(UpdateSchedule::RETARGETED)
        ->and($edited['session'])->toBeNull()
        ->and(WorkoutSession::query()->sole()->only(['planned_start_at', 'actual_start_at']))->toEqual($started->fresh()->only(['planned_start_at', 'actual_start_at']));
});

it('SCH-037 this day keeps the training time when it is unchanged, and saves no session row', function () {
    [$user] = editWorld();
    SheetSeed::day($user, '2026-09-24');

    editSchedule($user, ['date' => '2026-09-24', 'training_start' => '17:30']);

    expect(WorkoutSession::query()->count())->toBe(0);
});

it('SCH-037 NUT-024 this day refuses a past day and saves nothing', function () {
    [$user] = editWorld();
    $day = SheetSeed::day($user, '2026-09-21');
    $meals = $day->meals;

    expect(fn () => editSchedule($user, ['date' => '2026-09-21', 'meal_count' => 5]))->toThrow(DomainException::class, RetargetableDay::PAST)
        ->and($day->fresh()->meals)->toEqual($meals);
});

it('SCH-037 refuses a meal count outside 2 to 6, naming it', function (string $applyTo) {
    [$user] = editWorld();
    SheetSeed::day($user, '2026-09-23');
    FakeRebuild::bind();

    $refusal = null;
    try {
        editSchedule($user, ['apply_to' => $applyTo, 'meal_count' => 7]);
    } catch (FieldRefused $e) {
        $refusal = $e;
    }

    expect($refusal?->field)->toBe('meal_count');
})->with(['this_day', 'all_future_days']);

// MARK: all future days

it('SCH-037 all future days saves the window and meal count, edits the weekday\'s busy periods where they repeat, and rebuilds', function () {
    [$user] = editWorld();
    $rebuild = FakeRebuild::bind();

    // Wednesday shows Team meeting and Choir. Team meeting moves, Choir goes, and a new one is added.
    $dates = editSchedule($user, [
        'apply_to' => 'all_future_days', 'window_start' => '06:00', 'window_end' => '20:00', 'meal_count' => 4,
        'busy_periods' => [
            ['label' => 'Team meeting', 'start' => '09:30', 'end' => '10:30'],
            ['label' => 'School run', 'start' => '15:00', 'end' => '15:30'],
        ],
    ]);

    $profile = editProfile($user);
    expect($profile->eating_window)->toEqual(['start' => '06:00', 'end' => '20:00'])
        ->and($profile->meal_count)->toBe(4)
        ->and(array_map(fn (array $period) => array_diff_key($period, ['id' => true]), $profile->busy_periods))->toEqual([
            ['label' => 'Team meeting', 'start' => '09:30', 'end' => '10:30', 'weekdays' => [1, 2, 3, 4, 5]],
            ['label' => 'School run', 'start' => '15:00', 'end' => '15:30', 'weekdays' => [3]],
        ])
        ->and($rebuild->calls)->toHaveCount(1)
        ->and($dates['dates'])->toBe(['2026-10-15']);
});

it('SCH-037 all future days leaves busy periods of other weekdays alone, and keeps an unchanged one as stored', function () {
    [$user] = editWorld();
    Profile::query()->where('user_id', $user->id)->update(['busy_periods' => json_encode([
        ['id' => 'b1', 'label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2, 3]],
        ['label' => 'Tennis', 'start' => '18:00', 'end' => '19:00', 'weekdays' => [6]],
    ])]);
    FakeRebuild::bind();

    editSchedule($user, ['apply_to' => 'all_future_days', 'busy_periods' => [['label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30']]]);

    expect(editProfile($user)->busy_periods)->toEqual([
        ['id' => 'b1', 'label' => 'Team meeting', 'start' => '09:00', 'end' => '10:30', 'weekdays' => [1, 2, 3]],
        ['label' => 'Tennis', 'start' => '18:00', 'end' => '19:00', 'weekdays' => [6]],
    ]);
});

it('SCH-037 all future days removes the period taken off and edits the one kept by its label, on every day each repeats', function () {
    [$user] = editWorld();
    Profile::query()->where('user_id', $user->id)->update(['busy_periods' => json_encode([
        ['label' => 'Run', 'start' => '06:00', 'end' => '07:00', 'weekdays' => [3]],
        ['label' => 'Work', 'start' => '09:00', 'end' => '17:00', 'weekdays' => [1, 2, 3, 4, 5]],
    ])]);
    FakeRebuild::bind();

    editSchedule($user, ['apply_to' => 'all_future_days', 'busy_periods' => [['label' => 'Work', 'start' => '09:00', 'end' => '13:00']]]);

    expect(editProfile($user)->busy_periods)->toEqual([
        ['label' => 'Work', 'start' => '09:00', 'end' => '13:00', 'weekdays' => [1, 2, 3, 4, 5]],
    ]);
});

it('SCH-037 all future days keeps two periods renamed at once, each by its times', function () {
    [$user] = editWorld();
    Profile::query()->where('user_id', $user->id)->update(['busy_periods' => json_encode([
        ['id' => 'w1', 'label' => 'Work', 'start' => '09:00', 'end' => '17:00', 'weekdays' => [1, 2, 3, 4, 5]],
        ['id' => 'g1', 'label' => 'Gym', 'start' => '18:00', 'end' => '19:00', 'weekdays' => [1, 2, 3, 4, 5]],
    ])]);
    FakeRebuild::bind();

    editSchedule($user, ['apply_to' => 'all_future_days', 'busy_periods' => [
        ['label' => 'Office', 'start' => '09:00', 'end' => '17:00'],
        ['label' => 'Fitness', 'start' => '18:00', 'end' => '19:00'],
    ]]);

    expect(editProfile($user)->busy_periods)->toEqual([
        ['id' => 'w1', 'label' => 'Office', 'start' => '09:00', 'end' => '17:00', 'weekdays' => [1, 2, 3, 4, 5]],
        ['id' => 'g1', 'label' => 'Fitness', 'start' => '18:00', 'end' => '19:00', 'weekdays' => [1, 2, 3, 4, 5]],
    ]);
});

it('SCH-037 all future days reads a period with another name and other times as new on the weekday only', function () {
    [$user] = editWorld();
    Profile::query()->where('user_id', $user->id)->update(['busy_periods' => json_encode([
        ['id' => 'w1', 'label' => 'Work', 'start' => '09:00', 'end' => '17:00', 'weekdays' => [1, 2, 3, 4, 5]],
        ['id' => 's1', 'label' => 'School run', 'start' => '08:00', 'end' => '08:30', 'weekdays' => [1, 2, 3, 4, 5]],
    ])]);
    FakeRebuild::bind();

    editSchedule($user, ['apply_to' => 'all_future_days', 'busy_periods' => [
        ['label' => 'Work', 'start' => '09:00', 'end' => '17:00'],
        ['label' => 'Dentist', 'start' => '08:00', 'end' => '09:00'],
    ]]);

    $periods = editProfile($user)->busy_periods;
    expect(array_column($periods, 'label'))->toBe(['Work', 'Dentist'])
        ->and($periods[1]['weekdays'])->toBe([3])
        ->and($periods[1]['id'])->not->toBe('s1');
});

it('SCH-037 all future days keeps a renamed period, or a re-timed one, with its id on every day it repeats', function (array $sent) {
    [$user] = editWorld();
    Profile::query()->where('user_id', $user->id)->update(['busy_periods' => json_encode([
        ['id' => 'w1', 'label' => 'Work', 'start' => '09:00', 'end' => '17:00', 'weekdays' => [1, 2, 3, 4, 5]],
    ])]);
    FakeRebuild::bind();

    editSchedule($user, ['apply_to' => 'all_future_days', 'busy_periods' => [$sent]]);

    expect(editProfile($user)->busy_periods)->toEqual([['id' => 'w1', ...$sent, 'weekdays' => [1, 2, 3, 4, 5]]]);
})->with([
    'renamed' => [['label' => 'Office', 'start' => '09:00', 'end' => '17:00']],
    're-timed' => [['label' => 'Work', 'start' => '08:00', 'end' => '13:00']],
]);

it('SCH-037 all future days takes the same-named period that shares a time as the one edited', function () {
    [$user] = editWorld();
    Profile::query()->where('user_id', $user->id)->update(['busy_periods' => json_encode([
        ['label' => 'Meeting', 'start' => '10:00', 'end' => '11:00', 'weekdays' => [3]],
        ['label' => 'Meeting', 'start' => '15:00', 'end' => '16:00', 'weekdays' => [3, 5]],
    ])]);
    FakeRebuild::bind();

    editSchedule($user, ['apply_to' => 'all_future_days', 'busy_periods' => [['label' => 'Meeting', 'start' => '15:00', 'end' => '17:00']]]);

    expect(editProfile($user)->busy_periods)->toEqual([['label' => 'Meeting', 'start' => '15:00', 'end' => '17:00', 'weekdays' => [3, 5]]]);
});

it('SCH-037 refuses a busy period with no label or no length, naming it', function () {
    [$user] = editWorld();
    SheetSeed::day($user, '2026-09-23');

    $refusal = null;
    try {
        editSchedule($user, ['busy_periods' => [['label' => 'Gym', 'start' => '06:00', 'end' => '07:00'], ['label' => ' ', 'start' => '09:00', 'end' => '10:00']]]);
    } catch (FieldRefused $e) {
        $refusal = $e;
    }

    expect($refusal?->field)->toBe('busy_periods.1.label');
});

it('SCH-037 all future days sets the training time of the day\'s weekday only, keeping its minutes', function () {
    [$user] = editWorld();
    FakeRebuild::bind();

    editSchedule($user, ['date' => '2026-09-24', 'apply_to' => 'all_future_days', 'training_start' => '06:30', 'window_start' => '05:00']);

    expect(editProfile($user)->training_days)->toEqual([
        ['weekday' => 1, 'start' => '17:30', 'minutes' => 60],
        ['weekday' => 4, 'start' => '06:30', 'minutes' => 60],
    ]);
});

it('SCH-037 all future days on a rest weekday leaves the training days alone', function () {
    [$user] = editWorld();
    FakeRebuild::bind();

    editSchedule($user, ['apply_to' => 'all_future_days', 'training_start' => '06:30']);

    expect(editProfile($user)->training_days)->toEqual([
        ['weekday' => 1, 'start' => '17:30', 'minutes' => 60],
        ['weekday' => 4, 'start' => '17:30', 'minutes' => 60],
    ]);
});
