<?php

use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\SavedMealFood;
use App\Models\User;
use Database\Seeders\AccountSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs every area seeder in database/seeders by name', function () {
    expect(DatabaseSeeder::areaSeeders())->toContain(AccountSeeder::class)
        ->not->toContain(DatabaseSeeder::class);

    $this->seed();

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

it('seeds the known-password test account only in local and testing', function (string $environment, bool $seeded) {
    app()->instance('env', $environment);

    // Called directly, as `db:seed --force` would: the artisan command's own
    // production prompt is not the guard under test.
    app(AccountSeeder::class)->run();

    expect(User::where('email', 'test@example.com')->exists())->toBe($seeded);
})->with([
    'local' => ['local', true],
    'testing' => ['testing', true],
    'staging' => ['staging', false],
    'production' => ['production', false],
]);

it('FOOD-027 RCP-020 db:seed on a running production install corrects stale library flags and touches nothing else', function () {
    // docs/self-hosting.md, Updating: every area seeder must be safe to re-run on a live database.
    app()->instance('env', 'production');
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    // A user's data that points at library rows, and a recipe of their own.
    $user = User::factory()->create();
    $bacon = Food::where('source', 'curated')->where('source_id', 'bacon')->firstOrFail();
    $libraryRecipe = Recipe::where('slug', 'bacon-and-egg-breakfast-plate')->firstOrFail();
    $meal = DayTargetMeal::factory()->create(['user_id' => $user->id]);
    $plannedRecipe = PlannedRecipe::factory()->create(['meal_id' => $meal->id, 'recipe_id' => $libraryRecipe->id]);
    $plannedFood = PlannedFood::factory()->create(['meal_id' => $meal->id, 'food_id' => $bacon->id, 'position' => 1]);
    $savedMealFood = SavedMealFood::factory()->create(['food_id' => $bacon->id]);
    $ownRecipe = Recipe::factory()->create(['user_id' => $user->id, 'name' => 'My bacon plate']);
    $ownRecipe->ingredients()->create(['food_id' => $bacon->id, 'position' => 0, 'grams' => 50, 'is_cooked' => false]);

    $rows = fn () => [
        PlannedRecipe::findOrFail($plannedRecipe->id)->only(['recipe_id', 'servings', 'recipe']),
        PlannedFood::findOrFail($plannedFood->id)->only(['food_id', 'grams']),
        SavedMealFood::findOrFail($savedMealFood->id)->only(['food_id', 'grams']),
        Recipe::with('ingredients')->findOrFail($ownRecipe->id)->toArray(),
    ];
    $counts = fn () => collect(Schema::getTables())->pluck('name')
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    $before = [$rows(), $counts()];

    // An install seeded before Bacon lost gluten free, grain free and low FODMAP.
    $stale = ['gluten_free' => true, 'grain_free' => true, 'low_fodmap' => true];
    $bacon->forceFill($stale)->save();
    $libraryRecipe->forceFill($stale)->save();

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    $cleared = array_fill_keys(array_keys($stale), false);
    expect($bacon->fresh()?->only(array_keys($stale)))->toBe($cleared)
        ->and($libraryRecipe->fresh()?->only(array_keys($stale)))->toBe($cleared)
        ->and([$rows(), $counts()])->toBe($before)
        ->and(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

it('re-running db:seed in local keeps the one test account as it is', function () {
    app()->instance('env', 'local');
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    $account = User::where('email', 'test@example.com')->sole();

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::where('email', 'test@example.com')->sole()->toArray())->toBe($account->toArray());
});
