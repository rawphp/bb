<?php

use App\Capabilities\Support\SettingsValues;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Notifications\PlannedReminder;
use App\Domain\Notifications\ReminderDay;
use App\Domain\Notifications\ReminderKind;
use App\Domain\Notifications\ReminderMeal;
use App\Domain\Notifications\ReminderPlanner;
use App\Domain\Notifications\ReminderSwitches;
use App\Domain\Notifications\ReminderWeighIn;
use App\Domain\Notifications\ReminderWorkout;
use App\Domain\Placement\ClockTime;

// Ported from rp-clone's ReminderPlannerTests and RemindersModelTests. "Now" is Wednesday 2026-09-23 at 10:00 UTC.

function remindDay(int $offset): DateTimeImmutable
{
    return (new DateTimeImmutable('2026-09-23 00:00', new DateTimeZone('UTC')))->modify("+{$offset} days");
}

function remindAt(int $offset, string $hhmm): DateTimeImmutable
{
    $time = ClockTime::fromHhmm($hhmm);

    return remindDay($offset)->setTime($time->hour(), $time->minute());
}

function remindMeal(string $id, string $name, ?string $time, bool $checkedIn = false): ReminderMeal
{
    return new ReminderMeal($id, $name, $time === null ? null : ClockTime::fromHhmm($time), $checkedIn);
}

/** @return list<PlannedReminder> */
function remindPlan(array $days, ?ReminderSwitches $switches = null): array
{
    return ReminderPlanner::plan($days, $switches ?? new ReminderSwitches);
}

function allSwitchesOn(): ReminderSwitches
{
    $switches = new ReminderSwitches;
    foreach (ReminderKind::cases() as $kind) {
        $switches = $switches->with($kind, true);
    }

    return $switches;
}

function workoutDay(array $meals, bool $isDone = false, string $start = '17:30'): ReminderDay
{
    return new ReminderDay(remindDay(1), meals: $meals, workout: new ReminderWorkout('Upper A', ClockTime::fromHhmm($start), $isDone));
}

// NOTIF-001

it('NOTIF-001 reminds to weigh in at the scheduled time', function () {
    $planned = remindPlan([
        new ReminderDay(remindDay(0), weighIn: new ReminderWeighIn(ClockTime::at(18), isLogged: false)),
        new ReminderDay(remindDay(3), weighIn: new ReminderWeighIn(ClockTime::at(7, 30), isLogged: false)),
    ]);

    expect(array_map(fn (PlannedReminder $r) => $r->kind, $planned))->toBe([ReminderKind::WeighIn, ReminderKind::WeighIn])
        ->and(array_map(fn (PlannedReminder $r) => $r->fireAt, $planned))->toEqual([remindAt(0, '18:00'), remindAt(3, '07:30')])
        ->and($planned[0]->title)->toBe('Weigh-in')
        ->and($planned[0]->body)->toBe('Time to weigh in');
});

it('NOTIF-001 a day with a logged weigh-in has no reminder', function () {
    expect(remindPlan([new ReminderDay(remindDay(1), weighIn: new ReminderWeighIn(ClockTime::at(7), isLogged: true))]))->toBe([]);
});

it('NOTIF-001 NOTIF-011 a weigh-in reminder opens weigh-in entry on its day', function () {
    $planned = remindPlan([new ReminderDay(remindDay(1), weighIn: new ReminderWeighIn(ClockTime::at(7), isLogged: false))]);

    expect($planned[0]->url)->toBe('/today?date=2026-09-24&open=weigh-in');
});

it('NOTIF-001 only reminders that fell due since the last send go out, so a time already past is dropped', function () {
    $planned = remindPlan([
        new ReminderDay(remindDay(0), weighIn: new ReminderWeighIn(ClockTime::at(7), isLogged: false)),
        new ReminderDay(remindDay(0), weighIn: new ReminderWeighIn(ClockTime::at(9, 55), isLogged: false)),
        new ReminderDay(remindDay(0), weighIn: new ReminderWeighIn(ClockTime::at(10), isLogged: false)),
        new ReminderDay(remindDay(0), weighIn: new ReminderWeighIn(ClockTime::at(10, 1), isLogged: false)),
    ]);

    $due = ReminderPlanner::due($planned, after: remindAt(0, '09:50'), upTo: remindAt(0, '10:00'));

    expect(array_map(fn (PlannedReminder $r) => $r->fireAt, $due))->toEqual([remindAt(0, '09:55'), remindAt(0, '10:00')]);
});

it('NOTIF-001 reminders keep the local time the user set, also on the days the clocks change', function (string $date, int $hour) {
    $newYork = new DateTimeZone('America/New_York');
    $day = new DateTimeImmutable($date.' 00:00', $newYork);
    $planned = ReminderPlanner::plan([new ReminderDay(
        $day,
        weighIn: new ReminderWeighIn(ClockTime::at($hour), isLogged: false),
        workout: new ReminderWorkout('Upper A', ClockTime::at($hour + 2), isDone: false),
    )], allSwitchesOn());

    $byKind = collect($planned)->keyBy(fn (PlannedReminder $r) => $r->kind->value);
    expect($byKind['weigh_in']->fireAt->setTimezone($newYork)->format('H:i'))->toBe(sprintf('%02d:00', $hour))
        ->and($byKind['workout']->fireAt->setTimezone($newYork)->format('H:i'))->toBe(sprintf('%02d:00', $hour + 1));
})->with([['2026-03-08', 9], ['2026-11-01', 8]]);

// NOTIF-002

it('NOTIF-002 meal reminders are off by default', function () {
    expect(remindPlan([new ReminderDay(remindDay(1), meals: [remindMeal('m1', 'Lunch', '12:30')])]))->toBe([])
        ->and((new ReminderSwitches)->isOn(ReminderKind::Meal))->toBeFalse();
});

it('NOTIF-002 reminds at each meal time not yet checked in', function () {
    $planned = remindPlan([new ReminderDay(remindDay(1), meals: [
        remindMeal('m1', 'Breakfast', '08:00'),
        remindMeal('m2', 'Lunch', '12:30', checkedIn: true),
        remindMeal('m3', 'Snack', null),
        remindMeal('m4', 'Dinner', '19:30'),
    ])], (new ReminderSwitches)->with(ReminderKind::Meal, true));

    expect(array_map(fn (PlannedReminder $r) => $r->title, $planned))->toBe(['Breakfast', 'Dinner'])
        ->and(array_map(fn (PlannedReminder $r) => $r->body, $planned))->toBe(['Time to eat', 'Time to eat'])
        ->and(array_map(fn (PlannedReminder $r) => $r->fireAt, $planned))->toEqual([remindAt(1, '08:00'), remindAt(1, '19:30')])
        ->and(array_unique(array_map(fn (PlannedReminder $r) => $r->kind, $planned), SORT_REGULAR))->toBe([ReminderKind::Meal])
        ->and(array_unique(array_map(fn (PlannedReminder $r) => $r->id, $planned)))->toHaveCount(2);
});

it('NOTIF-002 the list is in time order, earliest first', function () {
    $meals = array_map(fn (int $i) => remindMeal("m{$i}", "Meal {$i}", (string) ClockTime::at(12, $i)), array_reverse(range(0, 9)));
    $planned = remindPlan([new ReminderDay(remindDay(1), meals: $meals)], (new ReminderSwitches)->with(ReminderKind::Meal, true));

    expect($planned[0]->title)->toBe('Meal 0')
        ->and($planned[9]->title)->toBe('Meal 9');
});

// NOTIF-003

it('NOTIF-003 reminds an hour before a workout, with the pre-workout meal not checked in yet', function () {
    $planned = remindPlan([workoutDay([remindMeal('m1', 'Meal 1', '08:00'), remindMeal('m2', 'Meal 2', '15:00')])]);

    expect($planned)->toHaveCount(1)
        ->and($planned[0]->kind)->toBe(ReminderKind::Workout)
        ->and($planned[0]->fireAt)->toEqual(remindAt(1, '16:30'))
        ->and($planned[0]->title)->toBe('Workout in 1 hour')
        ->and($planned[0]->body)->toBe('Upper A · Meal 2 not checked in yet')
        ->and($planned[0]->url)->toBe('/today?date=2026-09-24&open=workout');
});

it('NOTIF-003 the body shows a checked-in pre-workout meal', function () {
    $planned = remindPlan([workoutDay([remindMeal('m1', 'Meal 1', '08:00'), remindMeal('m2', 'Meal 2', '15:00', checkedIn: true)])]);

    expect($planned[0]->body)->toBe('Upper A · Meal 2 checked in');
});

it('NOTIF-003 the pre-workout meal is the last timed meal at or before the start', function () {
    $planned = remindPlan([workoutDay([
        remindMeal('m1', 'Meal 1', '08:00'), remindMeal('m2', 'Meal 2', '17:30'), remindMeal('m3', 'Meal 3', '19:00'), remindMeal('m4', 'Meal 4', null),
    ])]);

    expect($planned[0]->body)->toBe('Upper A · Meal 2 not checked in yet');
});

it('NOTIF-003 has no meal line when no meal comes before the workout', function () {
    $planned = remindPlan([workoutDay([remindMeal('m1', 'Meal 1', '19:00'), remindMeal('m2', 'Meal 2', null)])]);

    expect($planned[0]->body)->toBe('Upper A');
});

it('NOTIF-003 a done workout has no reminder', function () {
    expect(remindPlan([workoutDay([], isDone: true)]))->toBe([]);
});

it('NOTIF-003 a workout just after midnight is reminded the evening before', function () {
    $planned = remindPlan([workoutDay([], start: '00:30')]);

    expect($planned[0]->fireAt)->toEqual(remindAt(0, '23:30'));
});

// NOTIF-004

it('NOTIF-004 the weekly review reminder goes out at 09:00 on review day', function () {
    $planned = remindPlan([new ReminderDay(remindDay(3), hasWeeklyReview: true), new ReminderDay(remindDay(4))]);

    expect($planned)->toHaveCount(1)
        ->and($planned[0]->kind)->toBe(ReminderKind::WeeklyReview)
        ->and($planned[0]->title)->toBe('Weekly review available')
        ->and($planned[0]->body)->toBe('Your week is ready to review')
        ->and($planned[0]->fireAt)->toEqual(remindAt(3, '09:00'))
        ->and(ReminderPlanner::morningTime())->toEqual(ClockTime::at(9));
});

// NOTIF-005

it('NOTIF-005 the next mesocycle reminder goes out at 09:00 on the first day of a deload week', function () {
    $planned = remindPlan([new ReminderDay(remindDay(2), startsDeloadWeek: true)]);

    expect($planned[0]->kind)->toBe(ReminderKind::NextMesocycle)
        ->and($planned[0]->title)->toBe('Build your next mesocycle')
        ->and($planned[0]->body)->toBe('It is a deload week. Plan what comes next.')
        ->and($planned[0]->fireAt)->toEqual(remindAt(2, '09:00'));
});

// NOTIF-006

/** @return list<Meal> */
function retargetMeals(float $kcal = 700, float $protein = 50, float $fat = 20, float $carbs = 80): array
{
    return [new Meal('m1', 'Meal 1', new MealTarget($kcal, $protein, $fat, $carbs)), new Meal('m2', 'Meal 2', new MealTarget($kcal, $protein, $fat, $carbs))];
}

function changedMeal(array $meals, int $index, float $kcal = 0, float $protein = 0, float $fat = 0, float $carbs = 0): array
{
    $old = $meals[$index]->target;
    $meals[$index] = new Meal($meals[$index]->id, $meals[$index]->name, new MealTarget($old->kcal + $kcal, $old->proteinG + $protein, $old->fatG + $fat, $old->carbsG + $carbs));

    return $meals;
}

it('NOTIF-006 sends a notice when a meal with planned foods gets new targets', function () {
    $before = retargetMeals();
    $notice = ReminderPlanner::retargetNotice($before, changedMeal($before, 1, kcal: 120), ['m2'], remindDay(0), remindAt(0, '10:00'));

    expect($notice->kind)->toBe(ReminderKind::Retarget)
        ->and($notice->title)->toBe('Meal targets changed')
        ->and($notice->body)->toBe('New targets for Meal 2. Check its planned foods.')
        ->and($notice->fireAt)->toEqual(remindAt(0, '10:00'))
        ->and($notice->url)->toBe('/today?date=2026-09-23');
});

it('NOTIF-006 sends no notice for a meal without planned foods', function () {
    $before = retargetMeals();

    expect(ReminderPlanner::retargetNotice($before, changedMeal($before, 0, protein: 10), ['m2'], remindDay(0), remindAt(0, '10:00')))->toBeNull();
});

it('NOTIF-006 every macro counts, but rounding does not', function () {
    $before = retargetMeals();
    foreach ([['kcal' => 0.5], ['protein' => -1], ['fat' => 0.5], ['carbs' => -0.5]] as $change) {
        expect(ReminderPlanner::retargetNotice($before, changedMeal($before, 0, ...$change), ['m1'], remindDay(0), remindAt(0, '10:00')))->not->toBeNull();
    }

    expect(ReminderPlanner::retargetNotice($before, changedMeal($before, 0, kcal: 0.4, carbs: -0.3), ['m1'], remindDay(0), remindAt(0, '10:00')))->toBeNull();
});

it('NOTIF-006 lists several changed meals', function () {
    $before = retargetMeals();
    $after = changedMeal(changedMeal($before, 0, kcal: 100), 1, kcal: -100);

    expect(ReminderPlanner::retargetNotice($before, $after, ['m1', 'm2'], remindDay(0), remindAt(0, '10:00'))->body)
        ->toBe('New targets for Meal 1, Meal 2. Check their planned foods.');
});

it('NOTIF-006 a meal the re-target added or removed is not a changed meal', function () {
    $before = retargetMeals();
    $after = [$before[0], new Meal('m3', 'Intra-workout shake', new MealTarget(100, 0, 0, 25))];

    expect(ReminderPlanner::retargetNotice($before, $after, ['m2', 'm3'], remindDay(0), remindAt(0, '10:00')))->toBeNull();
});

// NOTIF-007

it('NOTIF-007 every kind has its own switch and a name, and only meals start off', function () {
    $switches = new ReminderSwitches;
    foreach (ReminderKind::cases() as $kind) {
        expect($switches->isOn($kind))->toBe($kind !== ReminderKind::Meal)
            ->and($kind->title())->not->toBe('');
    }

    expect(array_unique(array_map(fn (ReminderKind $k) => $k->title(), ReminderKind::cases())))->toHaveCount(count(ReminderKind::cases()))
        ->and(array_map(fn (ReminderKind $k) => $k->value, ReminderKind::cases()))->toBe(SettingsValues::NOTIFICATION_KINDS);
});

it('NOTIF-007 a kind switched off produces nothing, and the others still do', function () {
    $full = new ReminderDay(
        remindDay(1),
        weighIn: new ReminderWeighIn(ClockTime::at(7), isLogged: false),
        meals: [remindMeal('m1', 'Meal 1', '12:00')],
        workout: new ReminderWorkout('Upper A', ClockTime::at(17), isDone: false),
        hasWeeklyReview: true,
        startsDeloadWeek: true,
    );
    $kinds = fn (array $planned) => array_values(array_unique(array_map(fn (PlannedReminder $r) => $r->kind->value, $planned)));

    expect($kinds(remindPlan([$full], allSwitchesOn())))->toEqualCanonicalizing(['weigh_in', 'meal', 'workout', 'weekly_review', 'next_mesocycle']);

    $planned = ['weigh_in', 'meal', 'workout', 'weekly_review', 'next_mesocycle'];
    foreach (ReminderKind::cases() as $kind) {
        $left = $kinds(remindPlan([$full], allSwitchesOn()->with($kind, false)));
        expect($left)->toEqualCanonicalizing(array_values(array_diff($planned, [$kind->value])));
    }
});

it('NOTIF-007 a switch remembers the last value set for its kind', function () {
    $switches = (new ReminderSwitches)->with(ReminderKind::Meal, true)->with(ReminderKind::WeighIn, false);

    expect($switches->isOn(ReminderKind::Meal))->toBeTrue()
        ->and($switches->isOn(ReminderKind::WeighIn))->toBeFalse()
        ->and($switches->isOn(ReminderKind::Workout))->toBeTrue()
        ->and((new ReminderSwitches(['meal' => true]))->isOn(ReminderKind::Meal))->toBeTrue();
});
