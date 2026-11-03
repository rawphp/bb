<?php

use App\Actions\Meals\AddMealFoods;
use App\Capabilities\Nutrition\Data\MealFoodAddEntry;
use App\Capabilities\Support\FieldRefused;
use App\Domain\MealFit\Food as FitFood;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MealBuilderSeed as Seed;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Meal MealModelTests (MEAL-002), MealRecipeTests (RCP-001, RCP-002)
// and MealSaveTests. The fit is MealFitTest's and MealEditTest's; this proves what the action reads and writes.
uses(RefreshDatabase::class);

function addUlid(): string
{
    return strtolower((string) Str::ulid());
}

function addFood(Food $food, ?string $id = null, string $weighing = 'raw', ?float $grams = null): MealFoodAddEntry
{
    return new MealFoodAddEntry(id: $id ?? addUlid(), food_id: $food->id, weighing: $weighing, grams: $grams);
}

function addFitFood(Food $food, string $id, float $grams = 0, bool $locked = false, Weighing $weighing = Weighing::Raw): MealFood
{
    return new MealFood($id, new FitFood(id: $food->id, name: $food->name, kcal: $food->kcal, proteinG: $food->protein, fatG: $food->fat, carbsG: $food->carbs, cookedRatio: $food->cooked_ratio), $grams, $weighing, $locked);
}

function addDinnerTarget(): MealTarget
{
    return new MealTarget(kcal: 1040, proteinG: 68, fatG: 28, carbsG: 128);
}

it('MEAL-002 adds the foods with the client ids, in order after the meal, and fits the meal to its targets', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    [$chicken, $rice, $oil] = [Seed::chicken(), Seed::rice(), Seed::oil()];
    $ids = [addUlid(), addUlid(), addUlid()];

    $changed = app(AddMealFoods::class)($user, $meal, [addFood($chicken, $ids[0]), addFood($rice, $ids[1]), addFood($oil, $ids[2])]);

    $expected = (new MealFit)->fit([addFitFood($chicken, 'a'), addFitFood($rice, 'b'), addFitFood($oil, 'c')], addDinnerTarget())->foods;
    $rows = PlannedFood::query()->where('meal_id', $meal)->orderBy('position')->get();
    expect($changed)->toBeInstanceOf(DayTargetMeal::class)
        ->and($changed->id)->toBe($meal)
        ->and($rows->pluck('id')->all())->toBe($ids)
        ->and($rows->pluck('position')->all())->toBe([0, 1, 2])
        ->and($rows->pluck('grams')->all())->toBe(array_map(fn (MealFood $f) => $f->grams, $expected))
        ->and($rows->pluck('unit')->unique()->all())->toBe(['g'])
        ->and($rows->pluck('is_locked')->unique()->all())->toBe([false])
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and((new MealFit)->targetsMet((new MealFit)->totals($expected), addDinnerTarget()))->toBeTrue();
});

it('MEAL-002 takes positions after the largest of the meal\'s foods and recipe servings', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    Seed::planFood($meal, Seed::rice(), 100, 0);
    Seed::planRecipe($meal, Seed::stew(), 1, 4);

    app(AddMealFoods::class)($user, $meal, [addFood(Seed::oil(), $id = addUlid())]);

    expect(PlannedFood::query()->findOrFail($id)->position)->toBe(5);
});

it('MEAL-002 fits the foods already in the meal with the new ones', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    [$chicken, $rice, $oil] = [Seed::chicken(), Seed::rice(), Seed::oil()];
    $planned = Seed::planFood($meal, $chicken, 150, 0, cooked: true);
    Seed::planFood($meal, $rice, 80, 1);

    app(AddMealFoods::class)($user, $meal, [addFood($oil, $id = addUlid())]);

    $expected = (new MealFit)->fit([addFitFood($chicken, 'a', 150, weighing: Weighing::Cooked), addFitFood($rice, 'b', 80), addFitFood($oil, 'c')], addDinnerTarget())->foods;
    expect(array_values(Seed::foodGrams($meal)))->toBe(array_map(fn (MealFood $f) => $f->grams, $expected))
        ->and($planned->fresh()->is_cooked)->toBeTrue()
        ->and(PlannedFood::query()->findOrFail($id)->position)->toBe(2);
});

it('MEAL-002 MEAL-005 keeps a locked food at its exact grams when foods join', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0, locked: true);

    app(AddMealFoods::class)($user, $meal, [addFood(Seed::chicken()), addFood(Seed::oil())]);

    expect($rice->fresh()->grams)->toBe(80.0)
        ->and($rice->fresh()->is_locked)->toBeTrue();
});

it('MEAL-002 does not add a food the meal already holds, or one named twice in the call', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    [$chicken, $rice] = [Seed::chicken(), Seed::rice()];
    Seed::planFood($meal, $rice, 80, 0);

    app(AddMealFoods::class)($user, $meal, [addFood($rice), addFood($chicken, $id = addUlid()), addFood($chicken)]);

    expect(PlannedFood::query()->where('meal_id', $meal)->count())->toBe(2)
        ->and(PlannedFood::query()->where('meal_id', $meal)->where('food_id', $chicken->id)->value('id'))->toBe($id);
});

it('CAP-019 treats an entry id the meal already holds as a retry and changes nothing', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    [$chicken, $rice] = [Seed::chicken(), Seed::rice()];
    $entries = [addFood($chicken, addUlid()), addFood($rice, addUlid())];
    app(AddMealFoods::class)($user, $meal, $entries);
    $rows = PlannedFood::query()->where('meal_id', $meal)->orderBy('position')->get()->map->getAttributes()->all();
    Food::query()->whereKey($chicken->id)->delete();

    app(AddMealFoods::class)($user, $meal, $entries);

    expect(PlannedFood::query()->where('meal_id', $meal)->orderBy('position')->get()->map->getAttributes()->all())->toBe($rows);
});

it('CAP-019 answers not found for an entry id another meal, user or table already uses', function (string $owner) {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $taken = addUlid();
    match ($owner) {
        'another meal of the user' => Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0)->forceFill(['id' => $taken])->save(),
        'another user' => Seed::planFood(Seed::dinner(User::factory()->create()), Seed::rice(), 50, 0)->forceFill(['id' => $taken])->save(),
        'a planned recipe of another meal' => Seed::planRecipe(Seed::breakfast($user), Seed::stew(), 1, 0)->forceFill(['id' => $taken])->save(),
    };

    expect(fn () => app(AddMealFoods::class)($user, $meal, [addFood(Seed::chicken(), addUlid()), addFood(Seed::oil(), $taken)]))
        ->toThrow(ModelNotFoundException::class)
        ->and(PlannedFood::query()->where('meal_id', $meal)->count())->toBe(0);
})->with(['another meal of the user', 'another user', 'a planned recipe of another meal']);

it('MEAL-002 weighs a joining food cooked only when it has a cooked value', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);

    app(AddMealFoods::class)($user, $meal, [addFood(Seed::chicken(), $a = addUlid(), 'cooked'), addFood(Seed::oil(), $b = addUlid(), 'cooked'), addFood(Seed::rice(), $c = addUlid())]);

    expect(PlannedFood::query()->findOrFail($a)->is_cooked)->toBeTrue()
        ->and(PlannedFood::query()->findOrFail($b)->is_cooked)->toBeFalse()
        ->and(PlannedFood::query()->findOrFail($c)->is_cooked)->toBeFalse();
});

it('FOOD-045 joins a scanned food locked at its grams and fits the others around it', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0);

    app(AddMealFoods::class)($user, $meal, [addFood(Seed::chicken(), $id = addUlid(), grams: 120)]);

    $scanned = PlannedFood::query()->findOrFail($id);
    expect($scanned->grams)->toBe(120.0)
        ->and($scanned->is_locked)->toBeTrue()
        ->and($rice->fresh()->grams)->not->toBe(80.0);
});

it('FOOD-045 skips a scanned food the meal already holds, leaving the planned one as it was', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $chicken = Seed::chicken();
    $held = Seed::planFood($meal, $chicken, 150, 0, locked: true);

    app(AddMealFoods::class)($user, $meal, [addFood($chicken, addUlid(), grams: 300)]);

    expect(PlannedFood::query()->where('meal_id', $meal)->count())->toBe(1)
        ->and($held->fresh()->grams)->toBe(150.0);
});

it('FOOD-045 FOOD-006 weighs a scanned food cooked when it has a cooked value and the entry says cooked', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);

    app(AddMealFoods::class)($user, $meal, [addFood(Seed::chicken(), $id = addUlid(), 'cooked', grams: 120)]);

    $scanned = PlannedFood::query()->findOrFail($id);
    expect($scanned->is_cooked)->toBeTrue()
        ->and($scanned->is_locked)->toBeTrue()
        ->and($scanned->grams)->toBe(120.0);
});

it('CAP-019 treats a recipe entry id the meal already holds as a retry and changes nothing', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $entries = [new MealFoodAddEntry(id: addUlid(), recipe_id: Seed::stew($user)->id), addFood(Seed::rice(), addUlid())];
    app(AddMealFoods::class)($user, $meal, $entries);
    $rows = PlannedRecipe::query()->where('meal_id', $meal)->get()->map->getAttributes()->all();

    app(AddMealFoods::class)($user, $meal, $entries);

    expect(PlannedRecipe::query()->where('meal_id', $meal)->get()->map->getAttributes()->all())->toBe($rows)
        ->and(PlannedFood::query()->where('meal_id', $meal)->count())->toBe(1);
});

it('RCP-001 RCP-002 joins a recipe as unlocked servings fitted with the foods, with the meal\'s copy of the recipe', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $stew = Seed::stew();
    $entry = new MealFoodAddEntry(id: $id = addUlid(), recipe_id: $stew->id);

    app(AddMealFoods::class)($user, $meal, [addFood(Seed::rice()), $entry]);

    $planned = PlannedRecipe::query()->findOrFail($id);
    expect($planned->recipe_id)->toBe($stew->id)
        ->and($planned->position)->toBe(1)
        ->and($planned->is_locked)->toBeFalse()
        ->and($planned->servings)->toBeGreaterThan(0.0)
        ->and(round($planned->servings * 10))->toEqualWithDelta($planned->servings * 10, 1e-9)
        ->and($planned->recipe)->toEqual(json_decode(json_encode($stew->load('ingredients')->plannedCopy()), true));
});

it('RCP-001 does not add a recipe the meal already holds', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $stew = Seed::stew();
    $planned = Seed::planRecipe($meal, $stew, 1, 0);

    app(AddMealFoods::class)($user, $meal, [new MealFoodAddEntry(id: addUlid(), recipe_id: $stew->id), new MealFoodAddEntry(id: addUlid(), recipe_id: $stew->id)]);

    expect(PlannedRecipe::query()->where('meal_id', $meal)->count())->toBe(1)
        ->and($planned->fresh()->servings)->toBe(1.0);
});

it('RCP-002 keeps a locked serving at its servings when foods join', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $planned = Seed::planRecipe($meal, Seed::stew(), 1.5, 0, locked: true);

    app(AddMealFoods::class)($user, $meal, [addFood(Seed::rice()), addFood(Seed::oil())]);

    expect($planned->fresh()->servings)->toBe(1.5)
        ->and($planned->fresh()->is_locked)->toBeTrue();
});

it('RCP-001 refuses grams on a recipe entry, naming the field, and writes nothing', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $recipe = new MealFoodAddEntry(id: addUlid(), recipe_id: Seed::stew()->id, grams: 50);

    try {
        app(AddMealFoods::class)($user, $meal, [addFood(Seed::rice()), $recipe]);
        $this->fail('Expected a refusal.');
    } catch (FieldRefused $refused) {
        expect($refused->field)->toBe('entries.1.grams');
    }

    expect(PlannedFood::query()->count())->toBe(0)
        ->and(PlannedRecipe::query()->count())->toBe(0);
});

it('MEAL-002 saves a food fitted to nothing as 1 g and a serving as a tenth', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user, ['kcal' => 400, 'protein_g' => 10, 'fat_g' => 1, 'carbs_g' => 96]);
    Seed::planFood($meal, Seed::rice(), 400, 0, locked: true);

    app(AddMealFoods::class)($user, $meal, [addFood(Seed::oil(), $oil = addUlid()), new MealFoodAddEntry(id: $stew = addUlid(), recipe_id: Seed::stew()->id)]);

    expect(PlannedFood::query()->findOrFail($oil)->grams)->toBe(1.0)
        ->and(PlannedRecipe::query()->findOrFail($stew)->servings)->toBe(0.1);
});

it('MEAL-002 answers not found for another user\'s meal, a food or recipe the user cannot see, and keeps all of it unchanged', function () {
    $user = User::factory()->create();
    $mine = Seed::dinner($user);
    $theirs = Seed::dinner(User::factory()->create());
    $custom = Food::factory()->create(['user_id' => User::factory()->create()->id]);
    $secret = Seed::stew(User::factory()->create());

    expect(fn () => app(AddMealFoods::class)($user, $theirs, [addFood(Seed::rice())]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(AddMealFoods::class)($user, $mine, [addFood(Seed::rice()), addFood($custom)]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(AddMealFoods::class)($user, $mine, [new MealFoodAddEntry(id: addUlid(), recipe_id: $secret->id)]))->toThrow(ModelNotFoundException::class)
        ->and(PlannedFood::query()->count())->toBe(0);
});
