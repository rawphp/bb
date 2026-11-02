<?php

use App\Domain\DayBalance\DayBalance;
use App\Domain\DayBalance\Slot;
use App\Domain\Macros\MacroRules;
use App\Domain\Model\MealTarget;

// DAY-002: spreading what is left of a day across the meals that can still take it.

const REDISTRIBUTE_ACC = 1e-9;

function redistributeTarget(float $protein, float $fat, float $carbs): MealTarget
{
    return new MealTarget(4 * $protein + 9 * $fat + 4 * $carbs, $protein, $fat, $carbs);
}

function redistributeSlot(string $id, float $protein, float $fat, float $carbs): Slot
{
    return new Slot($id, redistributeTarget($protein, $fat, $carbs));
}

/** @param  list<Slot>  $slots */
function slotMacro(array $slots, string $macro): array
{
    return array_map(fn (Slot $s) => $s->target->{$macro}, $slots);
}

it('DAY-002 what is left is the day target less everything spent', function () {
    $day = new MealTarget(kcal: 2600, proteinG: 170, fatG: 70, carbsG: 320);
    $spent = [redistributeTarget(55, 20, 100), new MealTarget(kcal: 300, proteinG: 20, fatG: 10, carbsG: 30)];
    $left = DayBalance::remaining(day: $day, spent: $spent);

    // The first spent meal is 4 * 55 + 9 * 20 + 4 * 100 = 800 kcal.
    expect($left->kcal)->toEqual(2600 - 800 - 300)
        ->and($left->proteinG)->toEqual(95)
        ->and($left->fatG)->toEqual(40)
        ->and($left->carbsG)->toEqual(190);
});

it('DAY-002 an overspent day leaves less than nothing', function () {
    $day = new MealTarget(kcal: 2000, proteinG: 150, fatG: 60, carbsG: 200);
    $spent = [new MealTarget(kcal: 1500, proteinG: 100, fatG: 40, carbsG: 150), new MealTarget(kcal: 800, proteinG: 60, fatG: 30, carbsG: 70)];
    $left = DayBalance::remaining(day: $day, spent: $spent);

    expect($left->kcal)->toEqual(-300)
        ->and($left->proteinG)->toEqual(-10)
        ->and($left->fatG)->toEqual(-10)
        ->and($left->carbsG)->toEqual(-20);
});

it('DAY-002 each macro is spread in proportion to the meals current target for it', function () {
    $left = new MealTarget(kcal: 0, proteinG: 90, fatG: 30, carbsG: 200);
    $result = DayBalance::redistribute(remaining: $left, among: [redistributeSlot('a', 30, 10, 150), redistributeSlot('b', 60, 20, 50)]);

    expect(array_map(fn (Slot $s) => $s->id, $result))->toBe(['a', 'b'])
        ->and($result[0]->target->proteinG)->toEqualWithDelta(30, REDISTRIBUTE_ACC)
        ->and($result[1]->target->proteinG)->toEqualWithDelta(60, REDISTRIBUTE_ACC)
        ->and($result[0]->target->fatG)->toEqualWithDelta(10, REDISTRIBUTE_ACC)
        ->and($result[1]->target->fatG)->toEqualWithDelta(20, REDISTRIBUTE_ACC)
        // Carbs split 150 : 50.
        ->and($result[0]->target->carbsG)->toEqualWithDelta(150, REDISTRIBUTE_ACC)
        ->and($result[1]->target->carbsG)->toEqualWithDelta(50, REDISTRIBUTE_ACC);
});

it('DAY-002 a meals calories are four protein nine fat four carbs', function () {
    $left = new MealTarget(kcal: 0, proteinG: 40, fatG: 20, carbsG: 100);
    $result = DayBalance::redistribute(remaining: $left, among: [redistributeSlot('a', 10, 5, 20)]);

    expect($result[0]->target->kcal)->toEqualWithDelta(4 * 40 + 9 * 20 + 4 * 100, REDISTRIBUTE_ACC)
        ->and(MacroRules::kcal(proteinG: 40, carbsG: 100, fatG: 20))->toEqual(740);
});

it('DAY-002 a macro every receiving meal has none of is split equally', function () {
    $left = new MealTarget(kcal: 0, proteinG: 60, fatG: 30, carbsG: 90);
    $empty = new MealTarget(kcal: 0, proteinG: 0, fatG: 0, carbsG: 0);
    $result = DayBalance::redistribute(remaining: $left, among: [new Slot('a', $empty), new Slot('b', $empty)]);

    expect(slotMacro($result, 'proteinG'))->toEqual([30, 30])
        ->and(slotMacro($result, 'fatG'))->toEqual([15, 15])
        ->and(slotMacro($result, 'carbsG'))->toEqual([45, 45]);
});

it('DAY-002 one receiving meal takes the whole remainder', function () {
    $left = new MealTarget(kcal: 0, proteinG: 47, fatG: 22, carbsG: 92);
    $result = DayBalance::redistribute(remaining: $left, among: [redistributeSlot('a', 51, 21, 96)]);

    expect($result[0]->target->proteinG)->toEqualWithDelta(47, REDISTRIBUTE_ACC)
        ->and($result[0]->target->fatG)->toEqualWithDelta(22, REDISTRIBUTE_ACC)
        ->and($result[0]->target->carbsG)->toEqualWithDelta(92, REDISTRIBUTE_ACC);
});

it('DAY-002 no receiving meals gives nothing', function () {
    expect(DayBalance::redistribute(remaining: redistributeTarget(10, 10, 10), among: []))->toBe([]);
});

it('NUT-036 every receiving meal keeps five grams of fat and of carbs', function () {
    // Only 4 g of fat and 6 g of carbs are left for two meals.
    $left = new MealTarget(kcal: 0, proteinG: 50, fatG: 4, carbsG: 6);
    $result = DayBalance::redistribute(remaining: $left, among: [redistributeSlot('a', 30, 10, 50), redistributeSlot('b', 20, 10, 50)]);

    foreach ($result as $meal) {
        expect($meal->target->fatG)->toBeGreaterThanOrEqual(MacroRules::MEAL_MINIMUM_G)
            ->and($meal->target->carbsG)->toBeGreaterThanOrEqual(MacroRules::MEAL_MINIMUM_G);
    }
    expect($result[0]->target->kcal)->toEqualWithDelta(MacroRules::kcal(proteinG: 30, carbsG: 5, fatG: 5), REDISTRIBUTE_ACC);
});

it('DAY-002 protein never goes below zero', function () {
    // The day was eaten past its protein: less than nothing is left.
    $left = new MealTarget(kcal: 0, proteinG: -25, fatG: 20, carbsG: 40);
    $result = DayBalance::redistribute(remaining: $left, among: [redistributeSlot('a', 30, 10, 20), redistributeSlot('b', 20, 10, 20)]);

    expect(slotMacro($result, 'proteinG'))->toEqual([0, 0])
        ->and(slotMacro($result, 'fatG'))->toEqual([10, 10]);
});

it('DAY-021 the copy says small misses are fine', function () {
    expect(DayBalance::SMALL_MISSES_NOTE)
        ->toBe('Small misses are fine. Within 10% on calories and protein each day is a success.');
});
