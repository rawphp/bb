<?php

use App\Capabilities\Reads\Nutrition\MealPayload;
use App\Capabilities\Reads\Today\TodayPayload;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\FoodServing;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\TodaySnapshot;
use Illuminate\Support\Collection;
use Tests\Unit\Capabilities\Reads\Today\TodayWorld as W;

// The meal screen (rp-clone MealModelTests, MealStatusTests and MealMenuTests, the read cases), from in-memory rows.
// Wednesday 2026-09-23 is a hard day: breakfast 780 kcal, 51 P, 21 F, 96 C with 80 g oats and 30 g whey planned;
// lunch the same targets, checked in on track; dinner 1040 kcal, 68 P, 28 F, 128 C with nothing planned. Lower A
// starts at 17:30 and runs 30 minutes.

const BREAKFAST = '20260923-0000-4000-8000-000000000001';
const LUNCH = '20260923-0000-4000-8000-000000000002';
const DINNER = '20260923-0000-4000-8000-000000000003';

/** @return array<string, mixed> */
function mealShow(TodaySnapshot $snapshot, string $mealId, string $date = W::TODAY): array
{
    return MealPayload::build($snapshot, TrainSnapshot::day($date), $mealId)->toArray();
}

function mealRow(TodaySnapshot $snapshot, string $mealId): DayTargetMeal
{
    return $snapshot->meals->first(fn (DayTargetMeal $meal) => $meal->id === $mealId);
}

/** A food per 100 g, with a first household unit when `$serving` is [label, grams]. */
function mealFood(string $name, float $kcal, float $protein, float $fat, float $carbs, ?array $serving = null, ?float $cookedRatio = null): Food
{
    $food = (new Food)->forceFill([
        'id' => strtolower(str_replace(' ', '-', $name)), 'name' => $name, 'kcal' => $kcal, 'protein' => $protein,
        'fat' => $fat, 'carbs' => $carbs, 'cooked_ratio' => $cookedRatio,
    ]);

    return $food->setRelation('servings', new Collection($serving === null ? [] : [
        (new FoodServing)->forceFill(['id' => 1, 'food_id' => $food->id, 'label' => $serving[0], 'grams' => $serving[1]]),
    ]));
}

function chicken(): Food
{
    return mealFood('Chicken breast', 120, 23, 2, 0, ['fillet', 150], 0.75);
}

function oliveOil(): Food
{
    return mealFood('Olive oil', 884, 0, 100, 0, ['tbsp', 13.5]);
}

function whiteRice(): Food
{
    return mealFood('White rice', 130, 2.7, 0.3, 28, ['cup', 158]);
}

/**
 * Replaces a meal's planned foods and recipe servings.
 *
 * @param  list<array{food: Food, grams: float, position?: int, cooked?: bool, locked?: bool, unit?: string, id?: int}>  $foods
 * @param  list<array{servings: float, position: int, locked?: bool}>  $recipes  servings of TodayWorld's chili
 */
function planMeal(TodaySnapshot $snapshot, string $mealId, array $foods = [], array $recipes = []): void
{
    $planned = [];
    foreach ($foods as $index => $entry) {
        $planned[] = (new PlannedFood)->forceFill([
            'id' => $entry['id'] ?? $index + 1, 'user_id' => 1, 'meal_id' => $mealId, 'food_id' => $entry['food']->id,
            'position' => $entry['position'] ?? $index, 'grams' => $entry['grams'], 'is_cooked' => $entry['cooked'] ?? false,
            'is_locked' => $entry['locked'] ?? false, 'unit' => $entry['unit'] ?? 'g',
        ])->setRelation('food', $entry['food']);
    }
    $servings = array_map(fn (array $entry) => (new PlannedRecipe)->forceFill([
        'id' => $entry['position'] + 1, 'user_id' => 1, 'meal_id' => $mealId, 'recipe_id' => 'chili', 'position' => $entry['position'],
        'servings' => $entry['servings'], 'is_locked' => $entry['locked'] ?? false, 'recipe' => ['id' => 'chili', 'name' => 'Chili', 'servings' => 4],
    ])->setRelation('sourceRecipe', W::chili()), $recipes);

    mealRow($snapshot, $mealId)->setRelation('plannedFoods', new Collection($planned))->setRelation('plannedRecipes', new Collection($servings));
}

/** @param  array<string, ?string>  $times  meal id => "HH:MM" or null */
function retime(TodaySnapshot $snapshot, array $times, string $date = W::TODAY): void
{
    $target = $snapshot->dayTarget(TrainSnapshot::day($date));
    $meals = $target->meals;
    foreach ($meals as $index => $meal) {
        if (array_key_exists($meal['id'], $times)) {
            $meals[$index]['time'] = $times[$meal['id']];
        }
    }
    $target->forceFill(['meals' => $meals]);
}

/** Dinner planned at `$kcalPercent` of its calories and `$proteinPercent` of its protein: whey sets the protein, oil tops up. */
function dinnerAt(float $kcalPercent, float $proteinPercent): TodaySnapshot
{
    $snapshot = W::snapshot();
    $whey = 68 * $proteinPercent / 100 / 0.8;
    planMeal($snapshot, DINNER, [
        ['food' => W::food('whey'), 'grams' => $whey],
        ['food' => oliveOil(), 'grams' => (1040 * $kcalPercent / 100 - $whey * 4) / 8.84],
    ]);

    return $snapshot;
}

// MARK: MEAL-001

it('MEAL-001 shows four bars of the planned totals against the meal target, in whole numbers', function () {
    $payload = mealShow(W::snapshot(), BREAKFAST);

    // 80 g oats and 30 g whey: 424 kcal, 34.4 P, 7.4 F, 55.2 C against 780 kcal, 51 P, 21 F, 96 C.
    expect(array_column($payload['bars'], 'label'))->toBe(['Cal', 'P', 'F', 'C'])
        ->and(array_column($payload['bars'], 'value_text'))->toBe(['424/780', '34/51', '7/21', '55/96'])
        ->and(array_column($payload['bars'], 'fraction'))->toBe([0.54, 0.67, 0.33, 0.57])
        ->and(array_unique(array_column($payload['bars'], 'tone')))->toBe(['neutral']);
});

it('MEAL-001 shows the meal\'s id, name and time', function () {
    $payload = mealShow(W::snapshot(), DINNER);

    expect($payload['meal_id'])->toBe(DINNER)
        ->and($payload['title'])->toBe('Dinner')
        ->and($payload['time_text'])->toBe('7:30 PM');
});

it('MEAL-001 a meal without a name or time is "Meal n" with no time', function () {
    $snapshot = W::snapshot();
    $target = $snapshot->dayTarget($snapshot->date);
    $meals = $target->meals;
    $meals[2]['name'] = null;
    $meals[2]['time'] = null;
    $target->forceFill(['meals' => $meals]);

    $payload = mealShow($snapshot, DINNER);

    expect($payload['title'])->toBe('Meal 3')
        ->and($payload['time_text'])->toBeNull();
});

it('MEAL-001 a meal with nothing planned shows empty bars and no entries', function () {
    $payload = mealShow(W::snapshot(), DINNER);

    expect(array_column($payload['bars'], 'value_text'))->toBe(['0/1040', '0/68', '0/28', '0/128'])
        ->and(array_column($payload['bars'], 'fraction'))->toBe([0.0, 0.0, 0.0, 0.0])
        ->and($payload['entries'])->toBe([]);
});

it('MEAL-001 a bar is full, never more, when the meal is over its target', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, DINNER, [['food' => oliveOil(), 'grams' => 200]]);

    expect(mealShow($snapshot, DINNER)['bars'][2])->toBe(['label' => 'F', 'value_text' => '200/28', 'fraction' => 1.0, 'tone' => 'neutral']);
});

it('MEAL-001 a food row shows its name, amount and macros, keyed by its row', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, DINNER, [['food' => chicken(), 'grams' => 296, 'locked' => true, 'id' => 41]]);

    expect(mealShow($snapshot, DINNER)['entries'])->toBe([[
        'id' => '41',
        'kind' => 'food',
        'name' => 'Chicken breast',
        'amount_text' => '296 g (about 2 fillets)',
        'amount' => 296.0,
        'amount_unit' => 'g',
        'household_unit' => 'fillet',
        'weighing' => 'raw',
        'macros' => ['kcal' => '355 kcal', 'protein' => 'P 68', 'fat' => 'F 6', 'carbs' => 'C 0'],
        'is_locked' => true,
    ]]);
});

it('MEAL-001 FOOD-005 the amount shows grams with the household unit beside it, counted raw', function () {
    $snapshot = W::snapshot();
    // 150 g cooked chicken is 200 g raw, 1.3 fillets of 150 g; one tablespoon of oil is singular.
    planMeal($snapshot, DINNER, [
        ['food' => chicken(), 'grams' => 150, 'cooked' => true],
        ['food' => oliveOil(), 'grams' => 13.5],
    ]);
    $entries = mealShow($snapshot, DINNER)['entries'];

    expect(array_column($entries, 'amount_text'))->toBe(['150 g (about 1.3 fillets)', '14 g (about 1 tbsp)'])
        ->and(array_column($entries, 'weighing'))->toBe(['cooked', 'raw']);
});

it('MEAL-001 FOOD-005 a food without a household unit shows grams only', function () {
    $entry = mealShow(W::snapshot(), BREAKFAST)['entries'][0];

    expect($entry['name'])->toBe('Oats')
        ->and($entry['amount_text'])->toBe('80 g')
        ->and($entry['household_unit'])->toBeNull()
        ->and($entry['macros'])->toBe(['kcal' => '304 kcal', 'protein' => 'P 10', 'fat' => 'F 6', 'carbs' => 'C 53'])
        ->and($entry['is_locked'])->toBeFalse();
});

it('MEAL-001 FOOD-009 a food planned in ounces opens its amount in ounces', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, DINNER, [['food' => chicken(), 'grams' => 170.1, 'unit' => 'oz']]);
    $entry = mealShow($snapshot, DINNER)['entries'][0];

    expect($entry['amount_unit'])->toBe('oz')
        ->and($entry['amount'])->toBe(6.0)
        ->and($entry['amount_text'])->toBe('170 g (about 1.1 fillets)');
});

it('MEAL-001 RCP-002 recipe servings are rows among the foods, in their shared order', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, DINNER,
        [['food' => chicken(), 'grams' => 150, 'position' => 0], ['food' => whiteRice(), 'grams' => 80, 'position' => 2]],
        [['servings' => 1.5, 'position' => 1, 'locked' => true]],
    );
    $entries = mealShow($snapshot, DINNER)['entries'];

    expect(array_column($entries, 'name'))->toBe(['Chicken breast', 'Chili', 'White rice'])
        ->and($entries[1])->toBe([
            'id' => '2',
            'kind' => 'recipe',
            'name' => 'Chili',
            'amount_text' => '1.5 servings',
            'amount' => 1.5,
            'amount_unit' => 'servings',
            'household_unit' => null,
            'weighing' => null,
            // A serving is 350 kcal, 33 P, 15.5 F, 17 C.
            'macros' => ['kcal' => '525 kcal', 'protein' => 'P 50', 'fat' => 'F 23', 'carbs' => 'C 26'],
            'is_locked' => true,
        ]);
});

it('MEAL-001 RCP-002 one serving is singular', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, DINNER, recipes: [['servings' => 1, 'position' => 0]]);

    expect(mealShow($snapshot, DINNER)['entries'][0]['amount_text'])->toBe('1 serving');
});

// MARK: MEAL-003

it('MEAL-003 shows Targets met once every macro is within 5 g', function () {
    $snapshot = W::snapshot();
    // 100 g of the mix is exactly breakfast's targets.
    planMeal($snapshot, BREAKFAST, [['food' => W::food('fit'), 'grams' => 104]]);

    expect(mealShow($snapshot, BREAKFAST)['targets_met'])->toBe(['text' => 'Targets met', 'tone' => 'good']);
});

it('MEAL-003 shows no Targets met when a macro is more than 5 g off', function () {
    $snapshot = W::snapshot();
    // 34.4 g protein against 51.
    expect(mealShow($snapshot, BREAKFAST)['targets_met'])->toBeNull();

    planMeal($snapshot, BREAKFAST, [['food' => W::food('fit'), 'grams' => 110]]);
    // 56.1 g protein against 51.
    expect(mealShow($snapshot, BREAKFAST)['targets_met'])->toBeNull();
});

it('MEAL-003 shows no Targets met on an empty meal, even with targets of zero', function () {
    expect(mealShow(W::snapshot(), DINNER)['targets_met'])->toBeNull();
});

// MARK: MEAL-007

it('MEAL-007 names a macro the chosen foods cannot reach and the group to add from', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, DINNER, [['food' => chicken(), 'grams' => 296], ['food' => oliveOil(), 'grams' => 22]]);

    expect(mealShow($snapshot, DINNER)['hints'])->toBe([
        ['text' => 'Short 128 g carbs. Add a food from Carbs · Recommended.', 'group' => 'carbs_recommended'],
    ]);
});

it('MEAL-007 judges what the foods can reach, not the amounts they hold', function () {
    $snapshot = W::snapshot();
    // Far off the targets, but a Rebalance of the same foods gets there.
    planMeal($snapshot, DINNER, [
        ['food' => chicken(), 'grams' => 50, 'cooked' => true],
        ['food' => whiteRice(), 'grams' => 50],
        ['food' => oliveOil(), 'grams' => 5],
    ]);
    $payload = mealShow($snapshot, DINNER);

    expect($payload['targets_met'])->toBeNull()
        ->and($payload['hints'])->toBe([]);
});

it('MEAL-007 gives every short macro its own hint, and a locked food counts at its locked amount', function () {
    $snapshot = W::snapshot();
    // 10 g of oil held in place is all fat: protein, fat and carbs are short.
    planMeal($snapshot, DINNER, [['food' => oliveOil(), 'grams' => 10, 'locked' => true]]);

    expect(array_column(mealShow($snapshot, DINNER)['hints'], 'group'))->toBe(['protein_recommended', 'fat_recommended', 'carbs_recommended'])
        ->and(mealShow($snapshot, DINNER)['hints'][1]['text'])->toBe('Short 18 g fat. Add a food from Fat · Recommended.');
});

it('MEAL-007 shows no hint on an empty meal or once the targets are met', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, BREAKFAST, [['food' => W::food('fit'), 'grams' => 100]]);

    expect(mealShow($snapshot, DINNER)['hints'])->toBe([])
        ->and(mealShow($snapshot, BREAKFAST)['hints'])->toBe([]);
});

// MARK: MEAL-009

/** @return list<array{item: string, title: string, is_enabled: bool}> Today's card menu for the meal, as the meal screen names its fields */
function todayMenu(TodaySnapshot $snapshot, string $mealId): array
{
    $meal = collect(TodayPayload::build($snapshot)->toArray()['meals'])->firstWhere('id', $mealId);

    return array_map(fn (array $item) => ['item' => $item['action'], 'title' => $item['label'], 'is_enabled' => $item['enabled']], $meal['menu']);
}

/** @return array<string, bool> */
function menuEnabled(array $payload): array
{
    return array_column($payload['menu'], 'is_enabled', 'item');
}

it('MEAL-009 lists the ten menu items in spec order', function () {
    expect(array_column(mealShow(W::snapshot(), BREAKFAST)['menu'], 'title'))->toBe([
        'Edit macros/time', 'Load saved meal', 'Save as saved meal', 'Copy foods', 'Remove foods',
        'Check in meal', 'Check in at manual macros', 'Remove check-in', 'Still planning foods', 'Delete meal',
    ]);
});

it('MEAL-009 the meal screen and Today\'s card share one menu', function () {
    $snapshot = W::snapshot(states: [BREAKFAST => 'still_planning']);
    planMeal($snapshot, DINNER, recipes: [['servings' => 1.5, 'position' => 0]]);

    foreach ([BREAKFAST, LUNCH, DINNER] as $id) {
        expect(mealShow($snapshot, $id)['menu'])->toBe(todayMenu($snapshot, $id), $id);
    }
    expect(array_column(mealShow($snapshot, BREAKFAST)['menu'], 'title', 'item')['done_planning'])->toBe('Done planning foods');
});

it('MEAL-009 actions that need foods are off on an empty meal', function () {
    $enabled = menuEnabled(mealShow(W::snapshot(), DINNER));

    expect($enabled)->toBe([
        'edit_macros_time' => true, 'load_saved_meal' => true, 'save_as_saved_meal' => false, 'copy_foods' => false,
        'remove_foods' => false, 'check_in_meal' => true, 'check_in_manual' => true, 'remove_check_in' => false,
        'still_planning' => true, 'delete_meal' => true,
    ]);
});

it('MEAL-009 copy foods is on for a meal of only recipe servings, which saved meals cannot hold', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, DINNER, recipes: [['servings' => 1.5, 'position' => 0]]);
    $enabled = menuEnabled(mealShow($snapshot, DINNER));

    expect($enabled['copy_foods'])->toBeTrue()
        ->and($enabled['save_as_saved_meal'])->toBeFalse()
        ->and($enabled['remove_foods'])->toBeFalse();
});

it('MEAL-009 a checked-in meal offers Remove check-in instead of Check in', function () {
    $snapshot = W::snapshot();

    expect(menuEnabled(mealShow($snapshot, LUNCH)))->toMatchArray(['check_in_meal' => false, 'remove_check_in' => true])
        ->and(menuEnabled(mealShow($snapshot, BREAKFAST)))->toMatchArray(['check_in_meal' => true, 'remove_check_in' => false]);
});

it('MEAL-009 DAY-003 MEAL-045 a meal still being planned that misses its targets is badged and cannot be checked in', function () {
    $payload = mealShow(W::snapshot(states: [BREAKFAST => 'still_planning']), BREAKFAST);

    expect($payload['adjust_badge'])->toBe(['text' => 'Adjust foods', 'tone' => 'warning'])
        ->and(menuEnabled($payload)['check_in_meal'])->toBeFalse()
        ->and(mealShow(W::snapshot(), BREAKFAST)['adjust_badge'])->toBeNull();
});

// MARK: MEAL-010

it('MEAL-010 the meal just after the workout is post-workout, with the workout\'s time and the help text', function () {
    $snapshot = W::snapshot();
    retime($snapshot, [DINNER => '18:30']);

    expect(mealShow($snapshot, DINNER)['peri_workout'])->toBe([
        'kind' => 'post', 'title' => 'Post-workout', 'detail' => 'Workout 5:30 PM', 'help' => "These meals carry most of the day's carbs.",
    ]);
});

it('MEAL-010 the meals far from the workout have no tag', function () {
    $snapshot = W::snapshot();

    foreach ([BREAKFAST, LUNCH, DINNER] as $id) {
        expect(mealShow($snapshot, $id)['peri_workout'])->toBeNull($id);
    }
});

it('MEAL-010 the meal within three hours before the workout is pre-workout, three hours to the minute', function () {
    $snapshot = W::snapshot();
    retime($snapshot, [LUNCH => '14:30']);
    expect(mealShow($snapshot, LUNCH)['peri_workout']['kind'] ?? null)->toBe('pre')
        ->and(mealShow($snapshot, LUNCH)['peri_workout']['title'] ?? null)->toBe('Pre-workout');

    retime($snapshot, [LUNCH => '14:29']);
    expect(mealShow($snapshot, LUNCH)['peri_workout'])->toBeNull();
});

it('MEAL-010 only the latest meal before the workout is pre-workout', function () {
    $snapshot = W::snapshot();
    retime($snapshot, [BREAKFAST => '15:00', LUNCH => '16:30']);

    expect(mealShow($snapshot, BREAKFAST)['peri_workout'])->toBeNull()
        ->and(mealShow($snapshot, LUNCH)['peri_workout']['kind'] ?? null)->toBe('pre');
});

it('MEAL-010 post-workout reaches at most an hour after the workout ends', function () {
    $snapshot = W::snapshot();

    foreach (['18:00' => 'post', '19:00' => 'post', '19:01' => null] as $time => $kind) {
        retime($snapshot, [DINNER => $time]);
        expect(mealShow($snapshot, DINNER)['peri_workout']['kind'] ?? null)->toBe($kind, $time);
    }
});

it('MEAL-010 only the earliest meal after the workout is post-workout', function () {
    $snapshot = W::snapshot();
    retime($snapshot, [LUNCH => '18:15', DINNER => '18:45']);

    expect(mealShow($snapshot, LUNCH)['peri_workout']['kind'] ?? null)->toBe('post')
        ->and(mealShow($snapshot, DINNER)['peri_workout'])->toBeNull();
});

it('MEAL-010 a meal during the workout, on a rest day or without a time has no tag', function () {
    $snapshot = W::snapshot();
    retime($snapshot, [LUNCH => '17:30', DINNER => '17:45']);
    expect(mealShow($snapshot, LUNCH)['peri_workout'])->toBeNull()
        ->and(mealShow($snapshot, DINNER)['peri_workout'])->toBeNull();

    // Tuesday has no workout.
    retime($snapshot, ['20260922-0000-4000-8000-000000000003' => '18:30'], '2026-09-22');
    expect(mealShow($snapshot, '20260922-0000-4000-8000-000000000003', '2026-09-22')['peri_workout'])->toBeNull();

    retime($snapshot, [DINNER => null]);
    expect(mealShow($snapshot, DINNER)['peri_workout'])->toBeNull();
});

// MARK: MEAL-011

it('MEAL-011 a meal under target says how far under, calories then protein', function () {
    // 424 kcal against 780 and 34.4 g protein against 51.
    expect(mealShow(W::snapshot(), BREAKFAST)['pills'])->toBe([
        ['text' => 'Calories 356 under', 'tone' => 'warning'],
        ['text' => 'Protein 17 g under', 'tone' => 'warning'],
    ]);
});

it('MEAL-011 a meal over target says how far over', function () {
    $snapshot = W::snapshot();
    planMeal($snapshot, BREAKFAST, [['food' => W::food('oats'), 'grams' => 80], ['food' => W::food('whey'), 'grams' => 200]]);

    // 304 + 800 kcal against 780; 10.4 + 160 g protein against 51.
    expect(array_column(mealShow($snapshot, BREAKFAST)['pills'], 'text'))->toBe(['Calories 324 over', 'Protein 119 g over']);
});

it('MEAL-011 within ten percent is on track, and exactly ten percent still is', function () {
    foreach ([90.0, 100.0, 110.0] as $percent) {
        expect(mealShow(dinnerAt($percent, $percent), DINNER)['pills'])->toBe([
            ['text' => 'Calories on track', 'tone' => 'good'],
            ['text' => 'Protein on track', 'tone' => 'good'],
        ], "{$percent}%");
    }
});

it('MEAL-011 about twelve percent off says how far, and the two pills are judged apart', function () {
    expect(array_column(mealShow(dinnerAt(88, 88), DINNER)['pills'], 'text'))->toBe(['Calories 125 under', 'Protein 8 g under'])
        ->and(array_column(mealShow(dinnerAt(112, 112), DINNER)['pills'], 'text'))->toBe(['Calories 125 over', 'Protein 8 g over'])
        ->and(array_column(mealShow(dinnerAt(50, 100), DINNER)['pills'], 'text'))->toBe(['Calories 520 under', 'Protein on track']);
});

it('MEAL-011 an empty meal is under on both', function () {
    expect(array_column(mealShow(W::snapshot(), DINNER)['pills'], 'text'))->toBe(['Calories 1040 under', 'Protein 68 g under']);
});

// MARK: MEAL-046

it('MEAL-046 an empty meal shows the empty state, and a planned one does not', function () {
    $snapshot = W::snapshot();

    expect(mealShow($snapshot, DINNER)['empty'])->toBe([
        'title' => 'Your meal is empty',
        'note' => 'Because this meal has no foods in it, the app assumes you are eating this meal at target macros.',
    ])
        ->and(mealShow($snapshot, BREAKFAST)['empty'])->toBeNull();

    planMeal($snapshot, DINNER, recipes: [['servings' => 1, 'position' => 0]]);
    expect(mealShow($snapshot, DINNER)['empty'])->toBeNull();
});

// MARK: MEAL-047

it('MEAL-047 shows the check-in state with its text and tone', function () {
    $snapshot = W::snapshot();

    expect(mealShow($snapshot, LUNCH)['check_in'])->toBe(['state' => 'onTrack', 'text' => 'Checked in, on track', 'tone' => 'good'])
        ->and(mealShow($snapshot, BREAKFAST)['check_in'])->toBe(['state' => 'notCheckedIn', 'text' => 'Not checked in', 'tone' => 'neutral'])
        // Monday: breakfast well under target, lunch well over.
        ->and(mealShow($snapshot, '20260921-0000-4000-8000-000000000001', '2026-09-21')['check_in'])->toBe(['state' => 'under', 'text' => 'Checked in, under target', 'tone' => 'warning'])
        ->and(mealShow($snapshot, '20260921-0000-4000-8000-000000000002', '2026-09-21')['check_in'])->toBe(['state' => 'over', 'text' => 'Checked in, over target', 'tone' => 'warning']);
});

it('MEAL-047 answers not found for a meal that is not on the day', function () {
    mealShow(W::snapshot(), 'no-such-meal');
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
