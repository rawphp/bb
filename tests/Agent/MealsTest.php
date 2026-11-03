<?php

use App\Actions\Today\AddMeal;
use App\Capabilities\Today\Data\MealAddInput;
use App\Models\ManualShoppingItem;
use App\Models\MealCheckIn;
use App\Models\PlannedFood;
use Illuminate\Support\Carbon;
use Tests\Agent\Any;
use Tests\Agent\Coach;
use Tests\Support\MealBuilderSeed;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// Eval requests about the meals of a day (AGT-050). Checking in a meal runs on SheetSeed's Wednesday 23 September 2026
// (Breakfast 7:30, Lunch 12:30, Dinner 19:00); building one runs on MealBuilderSeed's Tuesday 13 October 2026 dinner.
uses()->group('warp-isolated');

afterEach(fn () => Carbon::setTestNow());

it('AGT-050 "I had breakfast as planned"', function () {
    Carbon::setTestNow('2026-09-23 09:00:00');
    $user = TrainSeed::user();
    SheetSeed::day($user);

    Coach::says($user, 'I had breakfast as planned', calls: [
        ['meal_check_in', ['meal_id' => SheetSeed::BREAKFAST]],
    ], reply: 'Checked in Breakfast as planned.');

    expect(MealCheckIn::query()->where('meal_id', SheetSeed::BREAKFAST)->count())->toBe(1);
});

it('AGT-050 "I had lunch early, at 11:30"', function () {
    Carbon::setTestNow('2026-09-23 13:00:00');
    $user = TrainSeed::user();
    SheetSeed::day($user);

    Coach::says($user, 'I had lunch early, at 11:30', calls: [
        ['meal_check_in', ['meal_id' => SheetSeed::LUNCH, 'time' => '11:30']],
    ], reply: 'Checked in Lunch at 11:30 AM.');

    expect(MealCheckIn::query()->where('meal_id', SheetSeed::LUNCH)->sole()->time)->toStartWith('11:30');
});

it('AGT-050 "remove my lunch check-in"', function () {
    Carbon::setTestNow('2026-09-23 13:00:00');
    $user = TrainSeed::user();
    SheetSeed::day($user);
    SheetSeed::checkIn(SheetSeed::LUNCH);

    Coach::says($user, 'Remove my lunch check-in', calls: [
        ['meal_check_in_undo', ['meal_id' => SheetSeed::LUNCH]],
    ], reply: 'Lunch is no longer checked in.');

    expect(MealCheckIn::query()->where('meal_id', SheetSeed::LUNCH)->count())->toBe(0);
});

it('AGT-050 AGT-010 a destructive request asks first: "delete my 3pm snack"', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TrainSeed::user();
    $day = SheetSeed::day($user);
    app(AddMeal::class)($user, new MealAddInput(SheetSeed::CLIENT_MEAL, '2026-09-23', '15:00'));
    $snack = $day->fresh()->meals[3]['id'];
    $delete = ['meal_delete', ['meal_id' => $snack]];

    Coach::says($user, 'Delete my 3pm snack', refused: [$delete], asks: $delete,
        reply: 'That removes the 3:00 PM meal and everything planned in it. To go ahead, send this line by itself:');

    expect($day->fresh()->meals)->toHaveCount(4);
});

it('AGT-050 "add 200 g of chicken breast to dinner"', function () {
    Carbon::setTestNow('2026-10-13 09:00:00');
    $user = TrainSeed::user();
    $dinner = MealBuilderSeed::dinner($user);
    $chicken = MealBuilderSeed::chicken();

    Coach::says($user, 'Add 200 g of chicken breast to dinner', calls: [
        ['meal_food_add', ['meal_id' => $dinner, 'entries' => [['id' => Any::id(), 'food_id' => (string) $chicken->id, 'grams' => 200]]]],
    ], reply: 'Added 200 g of Chicken breast to Dinner.');

    expect(MealBuilderSeed::foodGrams($dinner))->toBe(['Chicken breast' => 200.0]);
});

it('AGT-050 "make the chicken in my dinner 250 g"', function () {
    Carbon::setTestNow('2026-10-13 09:00:00');
    $user = TrainSeed::user();
    $dinner = MealBuilderSeed::dinner($user);
    $entry = MealBuilderSeed::planFood($dinner, MealBuilderSeed::chicken(), 200, 1);

    Coach::says($user, 'Make the chicken in my dinner 250 g', calls: [
        ['meal_food_amount_set', ['meal_id' => $dinner, 'entry_id' => (string) $entry->id, 'value' => 250, 'unit' => 'g']],
    ], reply: 'Dinner now has 250 g of Chicken breast.');

    expect(MealBuilderSeed::foodGrams($dinner))->toBe(['Chicken breast' => 250.0]);
});

it('AGT-050 "lock the chicken in my dinner"', function () {
    Carbon::setTestNow('2026-10-13 09:00:00');
    $user = TrainSeed::user();
    $dinner = MealBuilderSeed::dinner($user);
    $entry = MealBuilderSeed::planFood($dinner, MealBuilderSeed::chicken(), 200, 1);

    Coach::says($user, 'Lock the chicken in my dinner', calls: [
        ['meal_food_lock_set', ['meal_id' => $dinner, 'entry_id' => (string) $entry->id, 'locked' => true]],
    ], reply: 'Chicken breast is locked in Dinner, so a rebalance leaves its amount alone.');

    expect(PlannedFood::query()->find($entry->id)->is_locked)->toBeTrue();
});

it('AGT-050 AGT-010 a destructive request asks first: "take the rice out of my dinner"', function () {
    Carbon::setTestNow('2026-10-13 09:00:00');
    $user = TrainSeed::user();
    $dinner = MealBuilderSeed::dinner($user);
    MealBuilderSeed::planFood($dinner, MealBuilderSeed::chicken(), 200, 1);
    $rice = MealBuilderSeed::planFood($dinner, MealBuilderSeed::rice(), 150, 2);
    $remove = ['meal_food_remove', ['meal_id' => $dinner, 'entry_id' => (string) $rice->id]];

    Coach::says($user, 'Take the rice out of my dinner', refused: [$remove], asks: $remove,
        reply: 'That removes 150 g of Rice from Dinner. To go ahead, send this line by itself:');

    expect(MealBuilderSeed::foodGrams($dinner))->toHaveKey('Rice');
});

it('AGT-050 "add milk to my shopping list"', function () {
    Carbon::setTestNow('2026-10-13 09:00:00');
    $user = TrainSeed::user();

    Coach::says($user, 'Add milk to my shopping list', calls: [
        ['shopping_item_add', ['id' => Any::id(), 'text' => new Any('Milk')]],
    ], reply: 'Added milk to your shopping list.');

    expect(ManualShoppingItem::query()->where('user_id', $user->id)->sole()->text)->toBe('Milk');
});
