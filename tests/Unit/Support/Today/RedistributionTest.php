<?php

use App\Domain\Model\DayType;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;
use App\Support\Today\DayMeal;
use App\Support\Today\DayPlan;
use App\Support\Today\Redistribution;

// The Redistribute rules (DAY-002, DAY-003, SAFE-018), ported from rp-clone's TodayRedistributeTests. No database:
// a day is plain values. Wednesday 23 September: Breakfast 7:30, Lunch 12:30 (eaten), Dinner 19:00.

function redistributeDay(array $meals = [], array $offPlan = [], string $date = '2026-09-23'): DayPlan
{
    $meals = $meals ?: [
        new DayMeal('breakfast', 'Breakfast', ClockTime::at(7, 30), new MealTarget(700, 30, 20, 100)),
        new DayMeal('lunch', 'Lunch', ClockTime::at(12, 30), new MealTarget(780, 50, 25, 90), eaten: new MealTarget(800, 55, 25, 90)),
        new DayMeal('dinner', 'Dinner', ClockTime::at(19, 0), new MealTarget(900, 40, 25, 130)),
    ];

    return new DayPlan(new DateTimeImmutable($date, new DateTimeZone('UTC')), DayType::Moderate, new MealTarget(2600, 170, 70, 320), $meals, $offPlan);
}

function redistributeAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('UTC'));
}

function mealIdsOf(array $meals): array
{
    return array_map(fn (DayMeal $meal) => $meal->id, $meals);
}

it('DAY-002 offers the meals not checked in that are later today', function () {
    expect(mealIdsOf(Redistribution::receivable(redistributeDay(), redistributeAt('2026-09-23 06:00'))))->toBe(['breakfast', 'dinner']);
});

it('DAY-002 a meal whose time has passed cannot receive', function () {
    expect(mealIdsOf(Redistribution::receivable(redistributeDay(), redistributeAt('2026-09-23 07:30'))))->toBe(['dinner']);
});

it('DAY-002 a meal without a time counts as later', function () {
    $day = redistributeDay([new DayMeal('snack', 'Snack', null, new MealTarget(200, 10, 5, 20))]);

    expect(mealIdsOf(Redistribution::receivable($day, redistributeAt('2026-09-23 22:00'))))->toBe(['snack']);
});

it('DAY-002 a future day offers every meal not checked in, and a past day none', function () {
    expect(mealIdsOf(Redistribution::receivable(redistributeDay(), redistributeAt('2026-09-22 23:00'))))->toBe(['breakfast', 'dinner'])
        ->and(Redistribution::receivable(redistributeDay(), redistributeAt('2026-09-24 00:01')))->toBe([]);
});

it('DAY-003 a meal whose foods are all locked cannot receive; one with an unlocked food can', function () {
    $day = fn (bool $onlyLocked) => redistributeDay([
        new DayMeal('breakfast', 'Breakfast', ClockTime::at(7, 30), new MealTarget(700, 30, 20, 100), hasFoods: true, onlyLocked: $onlyLocked),
        new DayMeal('dinner', 'Dinner', ClockTime::at(19, 0), new MealTarget(900, 40, 25, 130)),
    ]);

    expect(mealIdsOf(Redistribution::receivable($day(true), redistributeAt('2026-09-23 06:00'))))->toBe(['dinner'])
        ->and(mealIdsOf(Redistribution::receivable($day(false), redistributeAt('2026-09-23 06:00'))))->toBe(['breakfast', 'dinner']);
});

it('DAY-002 the user can leave a meal out', function () {
    expect(mealIdsOf(Redistribution::chosen(redistributeDay(), redistributeAt('2026-09-23 06:00'), ['dinner'])))->toBe(['breakfast']);
});

it('DAY-002 what is left after what was eaten is shared in proportion to the receiving meals\' targets', function () {
    $day = redistributeDay();
    $targets = Redistribution::targets($day, Redistribution::receivable($day, redistributeAt('2026-09-23 06:00')));

    // 170 g of protein less the 55 g eaten at lunch, 3 : 4.
    expect(array_keys($targets))->toBe(['breakfast', 'dinner'])
        ->and($targets['breakfast']->proteinG)->toEqualWithDelta(115 * 3 / 7, 0.001)
        ->and($targets['dinner']->proteinG)->toEqualWithDelta(115 * 4 / 7, 0.001);
});

it('DAY-002 a meal left out keeps its target and counts as spent', function () {
    $day = redistributeDay();
    $targets = Redistribution::targets($day, Redistribution::chosen($day, redistributeAt('2026-09-23 06:00'), ['dinner']));

    expect(array_keys($targets))->toBe(['breakfast'])
        ->and($targets['breakfast']->proteinG)->toEqualWithDelta(170 - 55 - 40, 0.001);
});

it('DAY-001 DAY-002 food eaten off-plan is spent', function () {
    $day = redistributeDay(offPlan: [new MealTarget(350, 20, 18, 45)]);
    $targets = Redistribution::targets($day, Redistribution::receivable($day, redistributeAt('2026-09-23 06:00')));

    expect($targets['breakfast']->proteinG + $targets['dinner']->proteinG)->toEqualWithDelta(170 - 55 - 20, 0.001);
});

it('NUT-036 a receiving meal keeps 5 g of fat and carbs when nothing is left', function () {
    $day = redistributeDay(offPlan: [new MealTarget(3000, 200, 100, 400)]);
    $targets = Redistribution::targets($day, Redistribution::receivable($day, redistributeAt('2026-09-23 06:00')));

    expect($targets['dinner'])->toEqual(new MealTarget(9 * 5 + 4 * 5, 0, 5, 5));
});

it('SAFE-018 warns about a meal left under 5 g of carbs, but not about one already eaten', function () {
    $day = redistributeDay([
        new DayMeal('breakfast', 'Breakfast', ClockTime::at(7, 30), new MealTarget(300, 30, 10, 20), eaten: new MealTarget(300, 30, 10, 2)),
        new DayMeal('dinner', 'Dinner', ClockTime::at(19, 0), new MealTarget(900, 40, 25, 130)),
    ]);

    expect(Redistribution::warnings($day))->toBe([])
        ->and(Redistribution::warnings($day, ['dinner' => new MealTarget(300, 40, 10, 2)]))->toBe(['Dinner has under 5 g carbs. Add carbs to this meal.']);
});
