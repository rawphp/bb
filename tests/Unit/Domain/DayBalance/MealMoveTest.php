<?php

use App\Domain\DayBalance\DayBalance;
use App\Domain\DayBalance\MealMoveRefusal;
use App\Domain\DayBalance\MealMoveRefusalReason;
use App\Domain\DayBalance\Slot;
use App\Domain\Macros\MacroRules;
use App\Domain\Model\MealTarget;

// DAY-020: moving calories between a day's meals, and spreading a day's change in carbs over its meals. Calories go
// through carbs, so protein and fat hold (NUT-022) and every meal keeps 5 g of carbs (SAFE-010).

const MOVE_ACC = 1e-9;

function moveSlot(string $id, float $carbs, float $protein = 40, float $fat = 20): Slot
{
    return new Slot($id, new MealTarget(MacroRules::kcal($protein, $carbs, $fat), $protein, $fat, $carbs));
}

function moveRefusal(callable $call): MealMoveRefusal
{
    try {
        $call();
    } catch (MealMoveRefusal $refusal) {
        return $refusal;
    }

    throw new RuntimeException('Expected a MealMoveRefusal.');
}

it('DAY-020 calling for a move between meals takes carbs from one and gives them to the other', function () {
    $from = moveSlot('a', carbs: 100);
    $to = moveSlot('b', carbs: 60);
    $moved = DayBalance::moving(kcal: 200, from: $from, to: $to);

    // 200 kcal is 50 g of carbs.
    expect($moved->from->target->carbsG)->toEqualWithDelta(50, MOVE_ACC)
        ->and($moved->to->target->carbsG)->toEqualWithDelta(110, MOVE_ACC)
        ->and($moved->from->target->kcal)->toEqualWithDelta($from->target->kcal - 200, MOVE_ACC)
        ->and($moved->to->target->kcal)->toEqualWithDelta($to->target->kcal + 200, MOVE_ACC);
});

it('NUT-022 protein and fat never move', function () {
    $moved = DayBalance::moving(kcal: 120, from: moveSlot('a', carbs: 100, protein: 33, fat: 17), to: moveSlot('b', carbs: 20));

    expect($moved->from->target->proteinG)->toEqual(33)
        ->and($moved->from->target->fatG)->toEqual(17)
        ->and($moved->to->target->proteinG)->toEqual(40)
        ->and($moved->to->target->fatG)->toEqual(20);
});

it('SAFE-010 a meal that would keep less than five grams of carbs refuses the move', function () {
    $from = moveSlot('a', carbs: 20);

    // 80 kcal is 20 g: nothing would be left.
    expect(moveRefusal(fn () => DayBalance::moving(kcal: 80, from: $from, to: moveSlot('b', carbs: 20))))
        ->toMatchObject(['reason' => MealMoveRefusalReason::BelowCarbMinimum, 'id' => 'a']);

    // 60 kcal is 15 g: exactly 5 g are left.
    expect(DayBalance::moving(kcal: 60, from: $from, to: moveSlot('b', carbs: 20))->from->target->carbsG)
        ->toEqualWithDelta(5, MOVE_ACC);
});

it('DAY-020 a move needs calories and two different meals', function (float $kcal, string $fromId, string $toId) {
    expect(moveRefusal(fn () => DayBalance::moving(kcal: $kcal, from: moveSlot($fromId, carbs: 50), to: moveSlot($toId, carbs: 50))))
        ->toMatchObject(['reason' => MealMoveRefusalReason::Invalid, 'id' => null]);
})->with([
    'zero kcal' => [0.0, 'a', 'b'],
    'negative kcal' => [-50.0, 'a', 'b'],
    'NaN kcal' => [NAN, 'a', 'b'],
    'infinite kcal' => [INF, 'a', 'b'],
    'same meal' => [50.0, 'a', 'a'],
]);

it('DAY-020 a change in carbs is spread in proportion to the meals carbs', function () {
    $meals = [moveSlot('a', carbs: 60), moveSlot('b', carbs: 120)];
    $moved = DayBalance::shiftingCarbs(by: -30, over: $meals);

    expect(array_map(fn (Slot $s) => $s->target->carbsG, $moved))->toEqual([50, 100])
        ->and($moved[0]->target->kcal)->toEqualWithDelta($meals[0]->target->kcal - 40, MOVE_ACC)
        ->and($moved[1]->target->kcal)->toEqualWithDelta($meals[1]->target->kcal - 80, MOVE_ACC);

    $more = DayBalance::shiftingCarbs(by: 30, over: $meals);
    expect(array_map(fn (Slot $s) => $s->target->carbsG, $more))->toEqual([70, 140]);
});

it('DAY-020 meals with no carbs share a change equally', function () {
    $moved = DayBalance::shiftingCarbs(by: 20, over: [moveSlot('a', carbs: 0), moveSlot('b', carbs: 0)]);

    expect(array_map(fn (Slot $s) => $s->target->carbsG, $moved))->toEqual([10, 10]);
});

it('SAFE-010 a spread that leaves a meal under five grams is refused', function () {
    // b would drop from 6 g to 4.5 g.
    expect(moveRefusal(fn () => DayBalance::shiftingCarbs(by: -30, over: [moveSlot('a', carbs: 114), moveSlot('b', carbs: 6)])))
        ->toMatchObject(['reason' => MealMoveRefusalReason::BelowCarbMinimum, 'id' => 'b']);
});

it('DAY-020 a spread needs meals', function () {
    expect(moveRefusal(fn () => DayBalance::shiftingCarbs(by: 10, over: [])))
        ->toMatchObject(['reason' => MealMoveRefusalReason::NoMeals, 'id' => null]);
});

it('SAFE-010 adding carbs is never refused, even over a meal already under five grams', function () {
    // a has no carbs, so a proportional share leaves it at 0 g; it loses nothing.
    $moved = DayBalance::shiftingCarbs(by: 20, over: [moveSlot('a', carbs: 0), moveSlot('b', carbs: 100)]);
    expect(array_map(fn (Slot $s) => $s->target->carbsG, $moved))->toEqual([0, 120]);

    // b goes from 3 g up to 4 g: still under the floor, but higher than before.
    $move = DayBalance::moving(kcal: 4, from: moveSlot('a', carbs: 50), to: moveSlot('b', carbs: 3));
    expect($move->to->target->carbsG)->toEqualWithDelta(4, MOVE_ACC);
});

it('SAFE-010 taking carbs from a meal already under five grams is refused', function () {
    expect(moveRefusal(fn () => DayBalance::moving(kcal: 4, from: moveSlot('a', carbs: 4), to: moveSlot('b', carbs: 50))))
        ->toMatchObject(['reason' => MealMoveRefusalReason::BelowCarbMinimum, 'id' => 'a']);
});

it('DAY-020 a spread needs a finite amount of carbs', function (float $by) {
    expect(moveRefusal(fn () => DayBalance::shiftingCarbs(by: $by, over: [moveSlot('a', carbs: 50)])))
        ->toMatchObject(['reason' => MealMoveRefusalReason::Invalid, 'id' => null]);
})->with([
    'NaN' => [NAN],
    'infinite' => [INF],
    'minus infinite' => [-INF],
]);

it('SAFE-010 the 5 g carb floor allows for float rounding', function () {
    // 8.2 - 3.2 is 4.999999999999999 in floating point: still 5 g.
    expect(8.2 - 3.2)->toBeLessThan(MacroRules::MEAL_MINIMUM_G)
        ->and(DayBalance::shiftingCarbs(by: -3.2, over: [moveSlot('a', carbs: 8.2)])[0]->target->carbsG)
        ->toEqualWithDelta(5, MOVE_ACC);

    // A thousandth of a gram under is not rounding.
    expect(moveRefusal(fn () => DayBalance::shiftingCarbs(by: -3.201, over: [moveSlot('a', carbs: 8.2)])))
        ->toMatchObject(['reason' => MealMoveRefusalReason::BelowCarbMinimum, 'id' => 'a']);
});
