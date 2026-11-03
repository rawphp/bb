<?php

use App\Actions\Foods\MealFitFoods;
use App\Actions\Today\CopyDay;
use App\Actions\Today\CopyMeals;
use App\Actions\Today\CopyWeek;
use App\Domain\Copy\CopyOptions;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;
use App\Domain\WeekBuilder\MealNames;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\Plan;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\TodaySeed;

// What meal_copy, day_copy and week_copy save (MEAL-060, SCH-034, SCH-035), ported from rp-clone's CopyFoodsTests,
// CopyDayTests and CopyWeekTests. Which entries, amounts, locks and times a copy writes is CopyPlannerTest's; these
// prove the rows. The plan starts Saturday 2026-09-12 with Saturday reviews, so the plan week of Wednesday 23
// September runs from Saturday 19 to Friday 25; every day to 2026-10-09 has Breakfast 08:00, Lunch 12:30 and Dinner
// 19:30. Wednesday's dinner holds chicken locked at 200 g, a chili serving, rice 120 g cooked and oil 10 g.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 06:00:00'));
afterEach(fn () => Carbon::setTestNow());

function copyActionUser(string $last = '2026-10-09'): User
{
    $user = TodaySeed::user();
    TodaySeed::targets($user, last: $last);

    return $user;
}

/** The ids of a day's meals in their stored order. */
function copyActionMeal(User $user, string $date, int $index): string
{
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', $date)->firstOrFail();

    return $day->meals[$index - 1]['id'];
}

/** @return array{chicken: Food, rice: Food, oil: Food, chili: Recipe} */
function copyActionDinner(User $user, string $date = '2026-09-23'): array
{
    $chicken = Food::factory()->createOne(['name' => 'Chicken breast', 'kcal' => 165, 'protein' => 31, 'fat' => 3.6, 'carbs' => 0]);
    $rice = Food::factory()->createOne(['name' => 'Rice', 'kcal' => 130, 'protein' => 2.7, 'fat' => 0.3, 'carbs' => 28, 'cooked_ratio' => 2.5]);
    $oil = Food::factory()->createOne(['name' => 'Olive oil', 'kcal' => 884, 'protein' => 0, 'fat' => 100, 'carbs' => 0]);
    $chili = Recipe::factory()->createOne(['name' => 'Chili', 'servings' => 4]);
    RecipeIngredient::factory()->create(['recipe_id' => $chili->getKey(), 'food_id' => $chicken->getKey(), 'grams' => 400]);
    $dinner = copyActionMeal($user, $date, 3);
    PlannedFood::factory()->create(['meal_id' => $dinner, 'food_id' => $chicken->getKey(), 'position' => 0, 'grams' => 200, 'is_locked' => true]);
    PlannedRecipe::factory()->create(['meal_id' => $dinner, 'recipe_id' => $chili->getKey(), 'position' => 1, 'servings' => 0.5, 'is_locked' => true]);
    PlannedFood::factory()->create(['meal_id' => $dinner, 'food_id' => $rice->getKey(), 'position' => 2, 'grams' => 120, 'is_cooked' => true, 'unit' => 'oz']);
    PlannedFood::factory()->create(['meal_id' => $dinner, 'food_id' => $oil->getKey(), 'position' => 3, 'grams' => 10]);

    return ['chicken' => $chicken, 'rice' => $rice, 'oil' => $oil, 'chili' => $chili];
}

/** A planned food in a meal, of a new food. */
function copyActionFood(string $mealId, string $name = 'Oats', float $grams = 80): PlannedFood
{
    return PlannedFood::factory()->create([
        'meal_id' => $mealId, 'grams' => $grams,
        'food_id' => Food::factory()->createOne(['name' => $name, 'kcal' => 380, 'protein' => 13, 'fat' => 7, 'carbs' => 66])->getKey(),
    ]);
}

/**
 * A meal's planned foods and recipe servings in their order, as [kind, food or recipe id, grams or servings, locked].
 *
 * @return list<array{0: string, 1: string, 2: float, 3: bool}>
 */
function copyActionRows(string $mealId): array
{
    $rows = [
        ...PlannedFood::query()->where('meal_id', $mealId)->get()->map(fn (PlannedFood $f) => [$f->position, ['food', (string) $f->food_id, $f->grams, $f->is_locked]]),
        ...PlannedRecipe::query()->where('meal_id', $mealId)->get()->map(fn (PlannedRecipe $r) => [$r->position, ['recipe', (string) $r->recipe_id, $r->servings, $r->is_locked]]),
    ];
    usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);

    return array_map(fn (array $row) => $row[1], $rows);
}

/** @return list<string> the kinds and food or recipe ids a meal holds, in order */
function copyActionHolds(string $mealId): array
{
    return array_map(fn (array $row) => "{$row[0]}:{$row[1]}", copyActionRows($mealId));
}

/** @param  array<string, mixed>  $changes  by meal index, the attributes to change */
function copyActionEditDay(User $user, string $date, array $changes): void
{
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', $date)->firstOrFail();
    $meals = $day->meals;
    foreach ($changes as $index => $attributes) {
        $meals[$index - 1] = array_replace($meals[$index - 1], $attributes);
    }
    $day->meals = $meals;
    $day->save();
}

/** @return list<?string> a day's meal times in their stored order */
function copyActionTimes(User $user, string $date): array
{
    return array_column(DayTarget::query()->where('user_id', $user->id)->whereDate('date', $date)->firstOrFail()->meals, 'time');
}

/** @param  list<string>  $targets */
function copyActionCopyFoods(User $user, string $source, array $targets, CopyOptions $options = new CopyOptions): array
{
    return app(CopyMeals::class)($user, array_map(fn (string $id) => [$source, $id], $targets), $options);
}

// MARK: meal_copy (MEAL-060)

it('MEAL-060 copies the source\'s foods and recipe servings into each destination as new rows, in the source\'s order', function () {
    $user = copyActionUser();
    ['chicken' => $chicken, 'rice' => $rice, 'oil' => $oil, 'chili' => $chili] = copyActionDinner($user);
    $source = copyActionMeal($user, '2026-09-23', 3);
    $sourceRows = PlannedFood::query()->where('meal_id', $source)->pluck('id')->all();
    [$thursday, $friday] = [copyActionMeal($user, '2026-09-24', 3), copyActionMeal($user, '2026-09-25', 3)];

    $written = copyActionCopyFoods($user, $source, [$thursday, $friday]);

    $holds = ["food:{$chicken->id}", "recipe:{$chili->id}", "food:{$rice->id}", "food:{$oil->id}"];
    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([$thursday, $friday])
        ->and(copyActionHolds($thursday))->toBe($holds)
        ->and(copyActionHolds($friday))->toBe($holds)
        // The source keeps its own rows; the copies are new ones.
        ->and(PlannedFood::query()->where('meal_id', $source)->pluck('id')->all())->toBe($sourceRows)
        ->and(PlannedFood::query()->where('meal_id', $thursday)->pluck('id')->intersect($sourceRows)->all())->toBe([])
        // The rice keeps its cooked state and unit; the chili serving names its recipe.
        ->and(PlannedFood::query()->where('meal_id', $thursday)->where('food_id', $rice->id)->first()->only(['is_cooked', 'unit']))->toBe(['is_cooked' => true, 'unit' => 'oz'])
        ->and(PlannedRecipe::query()->where('meal_id', $thursday)->first()->recipe['name'])->toBe('Chili')
        ->and(PlannedFood::query()->where('meal_id', $thursday)->pluck('position')->all())->toBe([0, 2, 3]);
});

it('MEAL-060 RCP-021 a recipe edited after it was planned copies as it is now', function () {
    $user = copyActionUser();
    ['oil' => $oil, 'chili' => $chili] = copyActionDinner($user);
    $source = copyActionMeal($user, '2026-09-23', 3);
    // The source's serving was planned with the recipe as it was then; the recipe has since gained the oil.
    PlannedRecipe::query()->where('meal_id', $source)->firstOrFail()->update(['recipe' => $chili->plannedCopy()]);
    RecipeIngredient::factory()->create(['recipe_id' => $chili->getKey(), 'food_id' => $oil->getKey(), 'grams' => 20]);
    $thursday = copyActionMeal($user, '2026-09-24', 3);

    copyActionCopyFoods($user, $source, [$thursday]);

    expect(PlannedRecipe::query()->where('meal_id', $thursday)->firstOrFail()->recipe['ingredients'])
        ->toEqual($chili->fresh()->plannedCopy()['ingredients'])
        ->toHaveCount(2);
});

it('MEAL-060 by default the copies re-fit to the destination\'s targets, locked amounts held, and arrive unlocked', function () {
    $user = copyActionUser();
    ['chicken' => $chicken, 'rice' => $rice, 'oil' => $oil] = copyActionDinner($user);
    // Thursday's dinner has smaller targets than Wednesday's.
    copyActionEditDay($user, '2026-09-24', [3 => ['kcal' => 880, 'protein_g' => 68, 'fat_g' => 28, 'carbs_g' => 92]]);
    $thursday = copyActionMeal($user, '2026-09-24', 3);

    copyActionCopyFoods($user, copyActionMeal($user, '2026-09-23', 3), [$thursday]);

    $rows = copyActionRows($thursday);
    $chiliPerServing = new MealTarget(kcal: 165, proteinG: 31, fatG: 3.6, carbsG: 0);
    $fit = (new MealFit)->fit([
        new MealFood('chicken', MealFitFoods::of($chicken), 200, isLocked: true),
        RecipeServing::food('chili', 'Chili', $chiliPerServing, 0.5, true),
        new MealFood('rice', MealFitFoods::of($rice), 120, Weighing::Cooked),
        new MealFood('oil', MealFitFoods::of($oil), 10),
    ], new MealTarget(kcal: 880, proteinG: 68, fatG: 28, carbsG: 92))->foods;
    expect(array_column($rows, 2))->toBe([200.0, 0.5, max(1.0, $fit[2]->grams), max(1.0, $fit[3]->grams)])
        ->and($fit[2]->grams)->not->toBe(120.0)
        ->and(array_column($rows, 3))->toBe([false, false, false, false]);
});

it('MEAL-060 update target macros off and copy locked state on keep the source\'s amounts and locks', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    $thursday = copyActionMeal($user, '2026-09-24', 3);

    copyActionCopyFoods($user, copyActionMeal($user, '2026-09-23', 3), [$thursday], new CopyOptions(updateTargetMacros: false, copyLockedState: true));

    $rows = copyActionRows($thursday);
    expect(array_column($rows, 2))->toBe([200.0, 0.5, 120.0, 10.0])
        ->and(array_column($rows, 3))->toBe([true, true, false, false]);
});

it('MEAL-060 copying replaces the destination\'s foods and recipe servings', function () {
    $user = copyActionUser();
    ['chili' => $chili] = copyActionDinner($user);
    $breakfast = copyActionMeal($user, '2026-09-23', 1);
    $oats = copyActionFood($breakfast);

    copyActionCopyFoods($user, $breakfast, [copyActionMeal($user, '2026-09-23', 3)]);

    // Dinner held chicken, chili, rice and oil; now it holds breakfast's oats only.
    expect(copyActionHolds(copyActionMeal($user, '2026-09-23', 3)))->toBe(["food:{$oats->food_id}"])
        ->and(PlannedRecipe::query()->where('recipe_id', $chili->id)->count())->toBe(0);
});

it('MEAL-060 a checked-in destination and the source meal itself are skipped and keep what they hold', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    $source = copyActionMeal($user, '2026-09-23', 3);
    $lunch = copyActionMeal($user, '2026-09-23', 2);
    $kept = copyActionFood($lunch, 'Sandwich');
    MealCheckIn::factory()->create(['meal_id' => $lunch]);
    $before = copyActionRows($source);

    $written = copyActionCopyFoods($user, $source, [$lunch, $source]);

    expect($written)->toBe([])
        ->and(copyActionHolds($lunch))->toBe(["food:{$kept->food_id}"])
        ->and(copyActionRows($source))->toBe($before);
});

it('MEAL-060 a destination meal on a day before the plan is skipped', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    // The plan now starts Tuesday 22nd; Monday 21st keeps its target but is not in the plan.
    Plan::query()->where('user_id', $user->id)->update(['start_date' => '2026-09-22']);
    $before = copyActionMeal($user, '2026-09-21', 3);
    $inPlan = copyActionMeal($user, '2026-09-24', 3);

    $written = copyActionCopyFoods($user, copyActionMeal($user, '2026-09-23', 3), [$before, $inPlan]);

    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([$inPlan])
        ->and(copyActionHolds($before))->toBe([]);
});

it('MEAL-060 a user with no plan has no meal to copy to', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    Plan::query()->where('user_id', $user->id)->delete();

    expect(copyActionCopyFoods($user, copyActionMeal($user, '2026-09-23', 3), [copyActionMeal($user, '2026-09-24', 3)]))->toBe([]);
});

it('MEAL-060 a source with nothing to copy writes nothing', function () {
    $user = copyActionUser();
    $thursday = copyActionMeal($user, '2026-09-24', 1);
    $kept = copyActionFood($thursday);

    expect(copyActionCopyFoods($user, copyActionMeal($user, '2026-09-23', 3), [$thursday]))->toBe([])
        ->and(copyActionHolds($thursday))->toBe(["food:{$kept->food_id}"]);
});

it('MEAL-060 update meal times off leaves the times; on, the destination takes the source\'s and a shake keeps its own', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    copyActionEditDay($user, '2026-09-24', [2 => ['name' => MealNames::INTRA_WORKOUT_SHAKE]]);
    $source = copyActionMeal($user, '2026-09-23', 3);
    $targets = [copyActionMeal($user, '2026-09-24', 1), copyActionMeal($user, '2026-09-24', 2)];

    copyActionCopyFoods($user, $source, $targets);
    expect(copyActionTimes($user, '2026-09-24'))->toBe(['08:00', '12:30', '19:30']);

    copyActionCopyFoods($user, $source, $targets, new CopyOptions(updateMealTimes: true));
    // Both took the foods; only the breakfast took the source's 19:30.
    expect(copyActionTimes($user, '2026-09-24'))->toBe(['19:30', '12:30', '19:30'])
        ->and(PlannedFood::query()->where('meal_id', $targets[1])->count())->toBe(3);
});

it('MEAL-060 CAP-013 a failed write leaves every destination as it was', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    $thursday = copyActionMeal($user, '2026-09-24', 3);
    $kept = copyActionFood($thursday);
    $armed = true;
    // Left registered and disarmed after: Event::forget would also drop the model's own listeners.
    Event::listen('eloquent.creating: '.PlannedRecipe::class, function () use (&$armed) {
        if ($armed) {
            throw new RuntimeException('recipe save failed');
        }
    });

    try {
        expect(fn () => copyActionCopyFoods($user, copyActionMeal($user, '2026-09-23', 3), [copyActionMeal($user, '2026-09-25', 3), $thursday], new CopyOptions(updateMealTimes: true)))
            ->toThrow(RuntimeException::class, 'recipe save failed');
    } finally {
        $armed = false;
    }

    expect(copyActionHolds($thursday))->toBe(["food:{$kept->food_id}"])
        ->and(PlannedFood::query()->where('meal_id', copyActionMeal($user, '2026-09-25', 3))->count())->toBe(0);
});

it('MEAL-060 a copy reads the meals under their row lock inside its transaction, before it plans', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    $thursday = copyActionMeal($user, '2026-09-24', 3);
    copyActionFood($thursday);
    MealCheckIn::factory()->create(['meal_id' => copyActionMeal($user, '2026-09-24', 2)]);
    $level = DB::transactionLevel();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
    });

    $written = copyActionCopyFoods($user, copyActionMeal($user, '2026-09-23', 3), [$thursday, copyActionMeal($user, '2026-09-24', 2)]);

    // A check-in or a food added meanwhile waits for the copy or is seen by it: the plan is made from locked rows,
    // read as they are now even inside UndoLog's transaction (CopyMealsRaceTest proves what that keeps).
    $sql = array_column($queries, 'sql');
    $selectsOn = fn (string $table) => array_values(preg_grep("/^select .* from `{$table}`/", $sql));
    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([$thursday])
        ->and($queries[0]['level'])->toBeGreaterThan($level)
        ->and($sql[0])->toContain('from `day_target_meals`')->toEndWith('for update')
        ->and(array_filter($queries, fn (array $q) => $q['level'] <= $level))->toBe([]);
    foreach (['meal_check_ins', 'planned_foods', 'planned_recipes'] as $table) {
        expect($selectsOn($table))->not->toBe([]);
        foreach ($selectsOn($table) as $select) {
            expect($select)->toEndWith('for update');
        }
    }
});

// MARK: day_copy (SCH-034)

it('SCH-034 pairs meals by their order in the day', function () {
    $user = copyActionUser();
    ['chicken' => $chicken] = copyActionDinner($user);
    $oats = copyActionFood(copyActionMeal($user, '2026-09-23', 1));
    $soup = copyActionFood(copyActionMeal($user, '2026-09-23', 2), 'Soup');

    $written = app(CopyDay::class)($user, '2026-09-23', ['2026-09-24'], new CopyOptions);

    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe(array_map(fn (int $i) => copyActionMeal($user, '2026-09-24', $i), [1, 2, 3]))
        ->and(copyActionHolds(copyActionMeal($user, '2026-09-24', 1)))->toBe(["food:{$oats->food_id}"])
        ->and(copyActionHolds(copyActionMeal($user, '2026-09-24', 2)))->toBe(["food:{$soup->food_id}"])
        ->and(copyActionHolds(copyActionMeal($user, '2026-09-24', 3))[0])->toBe("food:{$chicken->id}");
});

it('SCH-034 pairs in time order, not stored order', function () {
    $user = copyActionUser();
    $oats = copyActionFood(copyActionMeal($user, '2026-09-23', 1));
    $soup = copyActionFood(copyActionMeal($user, '2026-09-23', 2), 'Soup');
    // Thursday's Breakfast moves to 13:00, after its Lunch: the day's order is Lunch, Breakfast, Dinner.
    copyActionEditDay($user, '2026-09-24', [1 => ['time' => '13:00']]);

    app(CopyDay::class)($user, '2026-09-23', ['2026-09-24'], new CopyOptions);

    expect(copyActionHolds(copyActionMeal($user, '2026-09-24', 2)))->toBe(["food:{$oats->food_id}"])
        ->and(copyActionHolds(copyActionMeal($user, '2026-09-24', 1)))->toBe(["food:{$soup->food_id}"]);
});

it('SCH-034 extra source meals are dropped and destination meals with no counterpart are left alone', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    copyActionFood(copyActionMeal($user, '2026-09-23', 1));
    copyActionFood(copyActionMeal($user, '2026-09-23', 2), 'Soup');
    // Thursday has two meals: Wednesday's Dinner has nowhere to go.
    $thursday = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-24')->firstOrFail();
    $thursday->meals = array_slice($thursday->meals, 0, 2);
    $thursday->save();
    // Friday has four: its fourth, at 21:00, has no Wednesday meal to take.
    $friday = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-25')->firstOrFail();
    $friday->meals = [...$friday->meals, ['id' => '01JBX0M1N2P3Q4R5S6T7V8W9X9', 'name' => 'Snack', 'time' => '21:00', 'kcal' => 200, 'protein_g' => 10, 'fat_g' => 5, 'carbs_g' => 20]];
    $friday->save();
    $snack = copyActionFood('01jbx0m1n2p3q4r5s6t7v8w9x9', 'Yoghurt');

    $written = app(CopyDay::class)($user, '2026-09-23', ['2026-09-24', '2026-09-25'], new CopyOptions);

    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([
        copyActionMeal($user, '2026-09-24', 1), copyActionMeal($user, '2026-09-24', 2),
        copyActionMeal($user, '2026-09-25', 1), copyActionMeal($user, '2026-09-25', 2), copyActionMeal($user, '2026-09-25', 3),
    ])->and(copyActionHolds('01jbx0m1n2p3q4r5s6t7v8w9x9'))->toBe(["food:{$snack->food_id}"]);
});

it('SCH-034 checked-in destination meals are skipped; a day whose meals are all checked in takes nothing', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    copyActionFood(copyActionMeal($user, '2026-09-23', 1));
    foreach ([1, 2, 3] as $index) {
        MealCheckIn::factory()->create(['meal_id' => copyActionMeal($user, '2026-09-22', $index)]);
    }
    MealCheckIn::factory()->create(['meal_id' => copyActionMeal($user, '2026-09-24', 1)]);

    $written = app(CopyDay::class)($user, '2026-09-23', ['2026-09-22', '2026-09-24'], new CopyOptions);

    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([copyActionMeal($user, '2026-09-24', 3)]);
});

it('SCH-034 copies to days in more than one week, with the MEAL-060 options on every day', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    copyActionEditDay($user, '2026-09-30', [3 => ['time' => '18:00']]);

    $written = app(CopyDay::class)($user, '2026-09-23', ['2026-09-24', '2026-09-30'], new CopyOptions(updateTargetMacros: false, updateMealTimes: true, copyLockedState: true));

    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([copyActionMeal($user, '2026-09-24', 3), copyActionMeal($user, '2026-09-30', 3)])
        ->and(array_column(copyActionRows(copyActionMeal($user, '2026-09-30', 3)), 2))->toBe([200.0, 0.5, 120.0, 10.0])
        ->and(array_column(copyActionRows(copyActionMeal($user, '2026-09-30', 3)), 3))->toBe([true, true, false, false])
        ->and(copyActionTimes($user, '2026-09-30'))->toBe(['08:00', '12:30', '19:30']);
});

it('SCH-034 meal times follow the source and a shake keeps its own', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    copyActionFood(copyActionMeal($user, '2026-09-23', 1));
    copyActionFood(copyActionMeal($user, '2026-09-23', 2), 'Soup');
    foreach (['2026-09-24', '2026-09-25'] as $date) {
        copyActionEditDay($user, $date, [1 => ['time' => '09:00'], 2 => ['time' => '13:00'], 3 => ['time' => '20:00']]);
    }
    copyActionEditDay($user, '2026-09-24', [2 => ['name' => MealNames::INTRA_WORKOUT_SHAKE]]);
    $saves = [];
    Event::listen('eloquent.saved: '.DayTarget::class, function (DayTarget $day) use (&$saves) {
        $saves[] = $day->date->format('Y-m-d');
    });

    app(CopyDay::class)($user, '2026-09-23', ['2026-09-24', '2026-09-25'], new CopyOptions(updateMealTimes: true));

    // One day target save per day, every moved meal in it.
    expect($saves)->toBe(['2026-09-24', '2026-09-25'])
        ->and(copyActionTimes($user, '2026-09-24'))->toBe(['08:00', '13:00', '19:30'])
        ->and(copyActionTimes($user, '2026-09-25'))->toBe(['08:00', '12:30', '19:30']);
});

it('SCH-034 never writes the source day, a day without a target or a day before the plan', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    // Wednesday's breakfast pairs with the old day's only meal, so the pair has something to copy.
    copyActionFood(copyActionMeal($user, '2026-09-23', 1));
    // A day from before the plan started keeps its target, but is not in the plan.
    $old = DayTarget::factory()->for($user)->create(['date' => '2026-09-05', 'meals' => [['name' => 'Dinner', 'time' => '19:30', 'kcal' => 900, 'protein_g' => 60, 'fat_g' => 30, 'carbs_g' => 90]]]);

    $written = app(CopyDay::class)($user, '2026-09-23', ['2026-09-23', '2026-09-05', '2026-10-20'], new CopyOptions);

    expect($written)->toBe([])
        ->and(PlannedFood::query()->where('meal_id', $old->meals[0]['id'])->count())->toBe(0);
});

it('SCH-034 a user with no plan has no day to copy to', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    Plan::query()->where('user_id', $user->id)->delete();

    expect(app(CopyDay::class)($user, '2026-09-23', ['2026-09-24'], new CopyOptions))->toBe([])
        ->and(PlannedFood::query()->where('meal_id', copyActionMeal($user, '2026-09-24', 3))->exists())->toBeFalse();
});

it('SCH-034 a source day with no target of the user\'s is not found', function () {
    $user = copyActionUser();
    TodaySeed::targets(User::factory()->create(), last: '2026-11-30');

    expect(fn () => app(CopyDay::class)($user, '2026-11-20', ['2026-09-24'], new CopyOptions))->toThrow(ModelNotFoundException::class);
});

// MARK: week_copy (SCH-035)

it('SCH-035 copies each day to the same weekday of the next plan week, with the default options', function () {
    $user = copyActionUser();
    ['chicken' => $chicken] = copyActionDinner($user);
    $oats = copyActionFood(copyActionMeal($user, '2026-09-25', 1));

    $written = app(CopyWeek::class)($user, '2026-09-19');

    // Wednesday 23rd to Wednesday 30th, Friday 25th to Friday 2 October.
    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([copyActionMeal($user, '2026-09-30', 3), copyActionMeal($user, '2026-10-02', 1)])
        ->and(copyActionHolds(copyActionMeal($user, '2026-10-02', 1)))->toBe(["food:{$oats->food_id}"])
        // Re-fit, locks off, times untouched.
        ->and(copyActionRows(copyActionMeal($user, '2026-09-30', 3))[0])->toBe(['food', $chicken->id, 200.0, false])
        ->and(array_column(copyActionRows(copyActionMeal($user, '2026-09-30', 3)), 3))->toBe([false, false, false, false])
        ->and(copyActionTimes($user, '2026-09-30'))->toBe(['08:00', '12:30', '19:30']);
});

it('SCH-035 any day of the plan week copies that whole week', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    copyActionFood(copyActionMeal($user, '2026-09-19', 1));

    $written = app(CopyWeek::class)($user, '2026-09-25');

    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([copyActionMeal($user, '2026-09-26', 1), copyActionMeal($user, '2026-09-30', 3)]);
});

it('SCH-035 a day with no target in the next week is skipped, and a checked-in meal there is left alone', function () {
    // The targets end on 2026-09-30, so Friday's counterpart (2026-10-02) has none.
    $user = copyActionUser(last: '2026-09-30');
    copyActionDinner($user);
    copyActionFood(copyActionMeal($user, '2026-09-23', 1));
    copyActionFood(copyActionMeal($user, '2026-09-25', 1));
    MealCheckIn::factory()->create(['meal_id' => copyActionMeal($user, '2026-09-30', 1)]);

    $written = app(CopyWeek::class)($user, '2026-09-19');

    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([copyActionMeal($user, '2026-09-30', 3)]);
});

it('SCH-035 never writes a day of the next week that is before the plan', function () {
    $user = copyActionUser();
    // The plan now starts Wednesday 23rd, part-way through the week of the 19th; the days before it keep their targets.
    Plan::query()->where('user_id', $user->id)->update(['start_date' => '2026-09-23']);
    copyActionDinner($user, '2026-09-16');
    copyActionFood(copyActionMeal($user, '2026-09-14', 1));

    $written = app(CopyWeek::class)($user, '2026-09-12');

    // Wednesday 16th goes to Wednesday 23rd, the plan's first day; Monday 21st is before the plan and keeps nothing.
    expect(array_map(fn (DayTargetMeal $m) => $m->id, $written))->toBe([copyActionMeal($user, '2026-09-23', 3)])
        ->and(PlannedFood::query()->where('meal_id', copyActionMeal($user, '2026-09-21', 1))->exists())->toBeFalse();
});

it('SCH-035 a user with no plan has no next week to copy to', function () {
    $user = copyActionUser();
    copyActionDinner($user);
    Plan::query()->where('user_id', $user->id)->delete();

    expect(fn () => app(CopyWeek::class)($user, '2026-09-19'))->toThrow(DomainException::class, 'There is no next week in the plan to copy to.')
        ->and(PlannedFood::query()->where('meal_id', copyActionMeal($user, '2026-09-30', 3))->exists())->toBeFalse();
});

it('SCH-035 the last plan week has no next week to copy to', function () {
    $user = copyActionUser();
    copyActionFood(copyActionMeal($user, '2026-10-07', 1));

    expect(fn () => app(CopyWeek::class)($user, '2026-10-03'))->toThrow(DomainException::class, 'There is no next week in the plan to copy to.');
});

it('SCH-035 a week with no day of the user\'s is not found', function () {
    $user = copyActionUser();
    TodaySeed::targets(User::factory()->create(), last: '2026-11-30');

    expect(fn () => app(CopyWeek::class)($user, '2026-11-14'))->toThrow(ModelNotFoundException::class);
});
