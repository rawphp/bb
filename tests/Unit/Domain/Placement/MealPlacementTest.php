<?php

namespace Tests\Unit\Domain\Placement;

use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\DayPlacement;
use App\Domain\Placement\MealPlacement;
use App\Domain\Placement\MealRole;
use App\Domain\Placement\MealSlot;
use App\Domain\Placement\PlacedMeal;
use App\Domain\Placement\WorkoutTime;

function t(int $hour, int $minute = 0): ClockTime
{
    return ClockTime::at($hour, $minute);
}

function window(ClockTime $start, ClockTime $end): ClockRange
{
    return new ClockRange($start, $end);
}

/** @return list<MealSlot> */
function freeMeals(int $count): array
{
    return array_map(fn (int $i) => new MealSlot("meal-{$i}"), range(0, $count - 1));
}

/**
 * The meals with the given ones set to a fixed time.
 *
 * @param  list<MealSlot>  $meals
 * @param  array<int, ClockTime>  $times
 * @return list<MealSlot>
 */
function fixMeals(array $meals, array $times): array
{
    foreach ($times as $i => $time) {
        $meals[$i] = new MealSlot($meals[$i]->id, $time);
    }

    return $meals;
}

function placed(?DayPlacement $result): DayPlacement
{
    expect($result)->not->toBeNull('expected the meals to fit');

    return $result;
}

/** @return list<string> */
function times(DayPlacement $day): array
{
    return array_map(fn (PlacedMeal $meal) => (string) $meal->time, $day->meals);
}

/** @return list<MealRole> */
function roles(DayPlacement $day): array
{
    return array_map(fn (PlacedMeal $meal) => $meal->role, $day->meals);
}

/** @return list<string> */
function ids(array $meals): array
{
    return array_map(fn (PlacedMeal|MealSlot $meal) => $meal->id, $meals);
}

function withRole(DayPlacement $day, MealRole $role): PlacedMeal
{
    $meal = array_values(array_filter($day->meals, fn (PlacedMeal $meal) => $meal->role === $role))[0] ?? null;
    expect($meal)->not->toBeNull("expected a {$role->value} meal");

    return $meal;
}

$day = window(t(7), t(21));
$workout = new WorkoutTime(t(17), 60);

const R = MealRole::Regular;
const PRE = MealRole::PreWorkout;
const POST = MealRole::PostWorkout;

// SCH-041

it('SCH-041 meals spaced evenly from first to last meal time', function () use ($day) {
    $result = placed(MealPlacement::place(freeMeals(5), $day));

    expect(times($result))->toBe(['07:00', '10:30', '14:00', '17:30', '21:00'])
        ->and(roles($result))->toBe([R, R, R, R, R])
        ->and($result->shake)->toBeNull();
});

it('SCH-041 at least two hours apart, or the result says it does not fit', function () {
    $short = window(t(8), t(14));

    expect(times(placed(MealPlacement::place(freeMeals(4), $short))))->toBe(['08:00', '10:00', '12:00', '14:00'])
        ->and(MealPlacement::place(freeMeals(5), $short))->toBeNull();
});

it('SCH-041 a busy period never contains a meal', function () use ($day) {
    $result = placed(MealPlacement::place(freeMeals(5), $day, [window(t(12), t(15))]));

    expect(times($result))->toBe(['07:00', '11:00', '15:00', '18:00', '21:00']);
});

it('SCH-041 busy periods that leave too little room do not fit', function () use ($day) {
    // Free time is 07:00-08:00 and 18:00-21:00: room for three meals two hours apart, not four.
    $busy = [window(t(8), t(18))];

    expect(times(placed(MealPlacement::place(freeMeals(3), $day, $busy))))->toBe(['07:00', '18:00', '21:00'])
        ->and(MealPlacement::place(freeMeals(4), $day, $busy))->toBeNull();
});

it('SCH-041 just under two hours apart does not fit', function () {
    expect(MealPlacement::place(freeMeals(4), window(t(8), t(13, 50))))->toBeNull();
});

it('SCH-041 a busy period running into the window start', function () use ($day) {
    $result = placed(MealPlacement::place(freeMeals(3), $day, [window(t(6), t(9))]));

    expect(times($result))->toBe(['09:00', '15:00', '21:00']);
});

it('SCH-041 busy time still counts as time between meals', function () {
    // 08:00 and 12:00 are four hours apart even though only 90 minutes of it is free.
    $result = placed(MealPlacement::place(freeMeals(2), window(t(8), t(12)), [window(t(9), t(11, 30))]));

    expect(times($result))->toBe(['08:00', '12:00']);
});

// SCH-042

it('SCH-042 pre-workout 90 minutes before and post-workout right after', function () use ($day, $workout) {
    $result = placed(MealPlacement::place(freeMeals(5), $day, [], $workout));

    expect(times($result))->toBe(['07:00', '11:15', '15:30', '18:00', '21:00'])
        ->and(roles($result))->toBe([R, R, PRE, POST, R]);
});

it('SCH-042 a busy period moves the peri-workout meals within their ranges', function () use ($day, $workout) {
    $busy = [window(t(15, 15), t(16)), window(t(18), t(18, 30))];

    $result = placed(MealPlacement::place(freeMeals(5), $day, $busy, $workout));

    // The nearest free time to 90 minutes before, and the first free time after the session.
    expect((string) withRole($result, PRE)->time)->toBe('15:10')
        ->and((string) withRole($result, POST)->time)->toBe('18:30');
});

it('SCH-042 pre and post may be closer than two hours', function () use ($day) {
    $short = new WorkoutTime(t(17), 45);

    $result = placed(MealPlacement::place(freeMeals(4), $day, [window(t(15), t(16))], $short));

    expect((string) withRole($result, PRE)->time)->toBe('16:00')
        ->and((string) withRole($result, POST)->time)->toBe('17:45');
});

it('SCH-042 a session before the first meal has no pre-workout meal', function () use ($day) {
    $early = new WorkoutTime(t(6), 60);

    $result = placed(MealPlacement::place(freeMeals(4), $day, [], $early));

    expect(times($result))->toBe(['07:00', '11:40', '16:20', '21:00'])
        ->and(roles($result))->toBe([POST, R, R, R]);
});

it('SCH-042 a fasted session far from the window has no peri-workout meals', function () {
    $result = placed(MealPlacement::place(freeMeals(3), window(t(12), t(21)), [], new WorkoutTime(t(6), 60)));

    expect(times($result))->toBe(['12:00', '16:30', '21:00'])
        ->and(roles($result))->toBe([R, R, R]);
});

it('SCH-042 post-workout never later than an hour after', function () use ($day, $workout) {
    // The hour after the session is busy, so there is no post-workout meal.
    $result = placed(MealPlacement::place(freeMeals(4), $day, [window(t(18), t(19, 5))], $workout));

    expect(times($result))->toBe(['07:00', '11:15', '15:30', '21:00'])
        ->and(roles($result))->toBe([R, R, PRE, R]);
});

it('SCH-042 a hand-set meal that crowds out the post-workout meal still fits', function () use ($day, $workout) {
    // 19:30 is set by hand, so no post-workout meal fits in 18:00-19:00 two hours from it.
    $meals = fixMeals(freeMeals(4), [3 => t(19, 30)]);

    $result = placed(MealPlacement::place($meals, $day, [], $workout));

    expect(times($result))->toBe(['07:00', '11:15', '15:30', '19:30'])
        ->and(roles($result))->toBe([R, R, PRE, R]);
});

it('SCH-042 no regular meal between pre- and post-workout', function () {
    // A third meal fits only between 14:55 pre-workout and 19:00 post-workout, which is not
    // allowed, so the pre-workout meal is left out and all three are spaced around the session.
    $result = placed(MealPlacement::place(
        freeMeals(3), window(t(14), t(20)), [window(t(14), t(14, 30))], new WorkoutTime(t(17), 120)));

    expect(times($result))->toBe(['14:30', '16:45', '19:00'])
        ->and(roles($result))->toBe([R, R, POST]);
});

// SCH-043

it('SCH-043 a shake only for sessions over an hour, and it moves with the workout', function () use ($day, $workout) {
    $hour = placed(MealPlacement::place(freeMeals(5), $day, [], $workout));
    $long = placed(MealPlacement::place(freeMeals(5), $day, [], new WorkoutTime(t(17), 61)));
    $moved = placed(MealPlacement::place(freeMeals(4), $day, [], new WorkoutTime(t(13), 75)));

    expect($hour->shake)->toBeNull()
        ->and((string) $long->shake)->toBe('17:00')
        ->and((string) $moved->shake)->toBe('13:00');
});

// SCH-044

it('SCH-044 a hand-set time stays and the other meals spread around it', function () use ($day) {
    $meals = fixMeals(freeMeals(5), [2 => t(12, 20)]);

    $result = placed(MealPlacement::place($meals, $day));

    expect(times($result))->toBe(['07:00', '09:40', '12:20', '16:40', '21:00'])
        ->and(ids($result->meals))->toBe(ids($meals), 'unfixed meals keep their order');
});

it('SCH-044 a hand-set time is kept even inside a busy period', function () use ($day) {
    $meals = fixMeals(freeMeals(3), [1 => t(13)]);

    $result = placed(MealPlacement::place($meals, $day, [window(t(12), t(15))]));

    expect(times($result))->toBe(['07:00', '13:00', '21:00']);
});

it('SCH-044 a hand-set meal in the pre-workout range is the pre-workout meal', function () use ($day, $workout) {
    $meals = fixMeals(freeMeals(5), [2 => t(15)]);

    $result = placed(MealPlacement::place($meals, $day, [], $workout));

    expect(times($result))->toBe(['07:00', '11:00', '15:00', '18:00', '21:00'])
        ->and(roles($result))->toBe([R, R, PRE, POST, R]);
});

it('SCH-044 a fixed meal in the hour before the session is the pre-workout meal', function () use ($day) {
    $meals = fixMeals(freeMeals(3), [1 => t(16, 50)]);

    $result = placed(MealPlacement::place($meals, $day, [], new WorkoutTime(t(17), 45)));

    expect(times($result))->toBe(['07:00', '16:50', '17:45'])
        ->and(roles($result))->toBe([R, PRE, POST]);
});

it('SCH-044 a fixed meal that blocks the pre-workout target is kept', function () use ($day, $workout) {
    $meals = fixMeals(freeMeals(4), [1 => t(13, 50)]);

    $result = placed(MealPlacement::place($meals, $day, [], $workout));

    expect(times($result))->toBe(['07:00', '13:50', '15:50', '18:00'])
        ->and(ids($result->meals))->toBe(ids($meals));
});

it('SCH-044 two hand-set meals may be less than two hours apart', function () use ($day) {
    $meals = fixMeals(freeMeals(2), [0 => t(12), 1 => t(13)]);

    expect(times(placed(MealPlacement::place($meals, $day))))->toBe(['12:00', '13:00']);
});

it('SCH-044 a hand-set meal just before the window stays first', function () use ($day) {
    $meals = fixMeals(freeMeals(3), [0 => t(6, 30)]);

    expect(times(placed(MealPlacement::place($meals, $day))))->toBe(['06:30', '13:45', '21:00']);
});

// SCH-045

it('SCH-045 a night-shift window crosses midnight', function () {
    $result = placed(MealPlacement::place(
        freeMeals(4), window(t(18), t(6)), [window(t(0), t(1))], new WorkoutTime(t(19), 60)));

    expect(times($result))->toBe(['18:00', '20:00', '01:00', '06:00'])
        ->and(roles($result))->toBe([PRE, POST, R, R]);
});

it('SCH-045 a workout crossing midnight', function () {
    $result = placed(MealPlacement::place(freeMeals(4), window(t(18), t(8)), [], new WorkoutTime(t(23, 30), 90)));

    expect(times($result))->toBe(['18:00', '22:00', '01:00', '08:00'])
        ->and(roles($result))->toBe([R, PRE, POST, R]);
});

it('SCH-045 a busy period crossing midnight', function () {
    $result = placed(MealPlacement::place(freeMeals(3), window(t(18), t(6)), [window(t(23), t(2))]));

    expect(times($result))->toBe(['18:00', '22:55', '06:00']);
});

it('SCH-045 the first meal at any hour', function () {
    expect(times(placed(MealPlacement::place(freeMeals(3), window(t(22), t(6))))))->toBe(['22:00', '02:00', '06:00']);
});

// SCH-036

it('SCH-036 optimize moves only unchecked meals', function () use ($day, $workout) {
    // Breakfast and the 10:00 meal are checked in, then a 17:00 workout is added.
    $meals = fixMeals(freeMeals(5), [0 => t(7), 1 => t(10)]);

    $result = placed(MealPlacement::place($meals, $day, [], $workout));

    expect(times($result))->toBe(['07:00', '10:00', '15:30', '18:00', '21:00'])
        ->and(ids($result->meals))->toBe(ids($meals))
        ->and(roles($result))->toBe([R, R, PRE, POST, R]);
});

it('SCH-036 the one unchecked meal left becomes the post-workout meal', function () use ($day, $workout) {
    $meals = fixMeals(freeMeals(3), [0 => t(7), 1 => t(11)]);

    $result = placed(MealPlacement::place($meals, $day, [], $workout));

    expect(times($result))->toBe(['07:00', '11:00', '18:00'])
        ->and($result->meals[2]->role)->toBe(POST);
});

it('SCH-036 optimize says so when the unchecked meals do not fit', function () {
    $meals = fixMeals(freeMeals(5), [0 => t(7), 4 => t(14)]);

    expect(MealPlacement::place($meals, window(t(7), t(14))))->toBeNull();
});
