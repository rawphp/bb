<?php

use App\Models\DayTargetMeal;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Food;
use App\Models\FoodServing;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\SavedMealFood;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Database\Seeders\AccountSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Query\Builder;
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

/** The library tables the seeders own; every other table must come through a re-seed unchanged. */
const LIBRARY_TABLES = ['foods', 'food_servings', 'recipes', 'recipe_ingredients', 'exercises', 'templates', 'template_slots'];

/**
 * Every row a re-seed must leave alone, and the library's IDs, which user rows point at.
 *
 * @return array<string, mixed>
 */
function reseedSnapshot(): array
{
    $rows = fn (Builder $query) => $query->get()->map(fn ($row) => json_encode($row))->sort()->values()->all();
    $userFoods = DB::table('foods')->whereNotNull('user_id')->select('id');
    $userRecipes = DB::table('recipes')->whereNotNull('user_id')->select('id');

    return [
        'other tables' => collect(Schema::getTables())->pluck('name')->diff(LIBRARY_TABLES)->sort()->values()
            ->mapWithKeys(fn (string $table) => [$table => $rows(DB::table($table))])->all(),
        'user foods' => $rows(DB::table('foods')->whereNotNull('user_id')),
        'user food servings' => $rows(DB::table('food_servings')->whereIn('food_id', $userFoods)),
        'user recipes' => $rows(DB::table('recipes')->whereNotNull('user_id')),
        'user recipe ingredients' => $rows(DB::table('recipe_ingredients')->whereIn('recipe_id', $userRecipes)),
        'user exercises' => $rows(DB::table('exercises')->whereNotNull('user_id')),
        'library food ids' => DB::table('foods')->whereNull('user_id')->orderBy('source_id')->pluck('id', 'source_id')->all(),
        'library recipe ids' => DB::table('recipes')->whereNull('user_id')->orderBy('slug')->pluck('id', 'slug')->all(),
        'library exercise ids' => DB::table('exercises')->whereNull('user_id')->orderBy('name')->pluck('id', 'name')->all(),
        'library template ids' => DB::table('templates')->orderBy('name')->pluck('id', 'name')->all(),
        'library row counts' => collect(LIBRARY_TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all(),
    ];
}

it('FOOD-027 RCP-020 db:seed on a running production install corrects stale library flags and touches nothing else', function () {
    // docs/self-hosting.md, Updating: every area seeder must be safe to re-run on a live database.
    app()->instance('env', 'production');
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    // A user's data that points at library rows, plus their own food, recipe and exercise with library names.
    $user = User::factory()->create();
    $bacon = Food::where('source', 'curated')->where('source_id', 'bacon')->firstOrFail();
    $bench = Exercise::whereNull('user_id')->where('name', 'Barbell Bench Press')->firstOrFail();
    $libraryRecipe = Recipe::where('slug', 'bacon-and-egg-breakfast-plate')->firstOrFail();

    $meal = DayTargetMeal::factory()->create(['user_id' => $user->id]);
    PlannedRecipe::factory()->create(['meal_id' => $meal->id, 'recipe_id' => $libraryRecipe->id]);
    PlannedFood::factory()->create(['meal_id' => $meal->id, 'food_id' => $bacon->id, 'position' => 1]);
    SavedMealFood::factory()->create(['food_id' => $bacon->id]);
    $ownFood = Food::factory()->custom($user)->create(['name' => 'Bacon']);
    FoodServing::factory()->create(['food_id' => $ownFood->id, 'label' => 'rasher', 'grams' => 20]);
    $ownRecipe = Recipe::factory()->create(['user_id' => $user->id, 'name' => 'Bacon and egg breakfast plate']);
    $ownRecipe->ingredients()->create(['food_id' => $bacon->id, 'position' => 0, 'grams' => 50, 'is_cooked' => false]);

    $session = WorkoutSession::factory()->create(['user_id' => $user->id]);
    WorkoutSet::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $bench->id]);
    ExerciseFeedback::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $bench->id]);
    ExerciseNote::factory()->create(['user_id' => $user->id, 'exercise_id' => $bench->id]);
    ExerciseLoadStep::factory()->create(['user_id' => $user->id, 'exercise_id' => $bench->id]);
    Exercise::factory()->custom($user)->create(['name' => 'Barbell Bench Press', 'video_url' => null]);

    $before = reseedSnapshot();

    // An install seeded before Bacon lost gluten free, grain free and low FODMAP.
    $stale = ['gluten_free' => true, 'grain_free' => true, 'low_fodmap' => true];
    $bacon->forceFill($stale)->save();
    $libraryRecipe->forceFill($stale)->save();

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    $cleared = array_fill_keys(array_keys($stale), false);
    expect($bacon->fresh()?->only(array_keys($stale)))->toBe($cleared)
        ->and($libraryRecipe->fresh()?->only(array_keys($stale)))->toBe($cleared)
        ->and(reseedSnapshot())->toBe($before)
        ->and(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

it('re-running db:seed in local keeps the one test account as it is', function () {
    app()->instance('env', 'local');
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    $account = User::where('email', 'test@example.com')->sole();

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::where('email', 'test@example.com')->sole()->toArray())->toBe($account->toArray());
});
