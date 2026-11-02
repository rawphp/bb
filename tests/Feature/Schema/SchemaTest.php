<?php

use App\Enums\MeasurementSource;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\ExerciseLoadStep;
use App\Models\ExerciseNote;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\FoodRecent;
use App\Models\FoodServing;
use App\Models\MealCheckIn;
use App\Models\MealPlanningState;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\OffPlanEntry;
use App\Models\OtherActivity;
use App\Models\PendingSignInCode;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use App\Models\Step;
use App\Models\Template;
use App\Models\TemplateSlot;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Every model in app/Models, by class name. */
function allModels(): array
{
    return collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path) => 'App\\Models\\'.basename($path, '.php'))
        ->all();
}

/** Builds one row in every user-owned table for the user, with their custom food and exercise used everywhere they can be. */
function fillAccount(User $user): void
{
    $food = Food::factory()->custom($user)->create();
    FoodServing::factory()->for($food)->create();
    $exercise = Exercise::factory()->custom($user)->create();

    Profile::factory()->for($user)->create();
    Plan::factory()->for($user)->create();
    $phase = Phase::factory()->for($user)->create();
    Mesocycle::factory()->for($user)->create(['phase_id' => $phase->id]);
    PlanWeek::factory()->for($user)->create();
    OtherActivity::factory()->for($user)->create();

    $day = DayTarget::factory()->for($user)->create();
    $meal = $day->mealIndex()->firstOrFail();
    PlannedFood::factory()->create(['user_id' => $user->id, 'meal_id' => $meal->id, 'food_id' => $food->id]);
    MealCheckIn::factory()->create(['user_id' => $user->id, 'meal_id' => $meal->id]);
    MealPlanningState::factory()->create(['user_id' => $user->id, 'meal_id' => $meal->id]);
    OffPlanEntry::factory()->for($user)->create(['food_id' => $food->id]);

    $recipe = Recipe::factory()->for($user)->create();
    RecipeIngredient::factory()->for($recipe)->create(['food_id' => $food->id]);
    PlannedRecipe::factory()->create(['user_id' => $user->id, 'meal_id' => $meal->id, 'recipe_id' => $recipe->id]);

    $saved = SavedMeal::factory()->for($user)->create();
    SavedMealFood::factory()->for($saved)->create(['food_id' => $food->id]);
    FoodFavorite::factory()->for($user)->create(['food_id' => $food->id]);
    FoodRecent::factory()->for($user)->create(['food_id' => $food->id]);

    $session = WorkoutSession::factory()->for($user)->create();
    WorkoutSet::factory()->for($session, 'session')->create(['exercise_id' => $exercise->id]);
    MuscleFeedback::factory()->for($session, 'session')->create();
    ExerciseFeedback::factory()->for($session, 'session')->create(['exercise_id' => $exercise->id]);
    ExerciseNote::factory()->for($user)->create(['exercise_id' => $exercise->id]);
    ExerciseLoadStep::factory()->for($user)->create(['exercise_id' => $exercise->id]);

    WeighIn::factory()->for($user)->create();
    WeighInScheduleEntry::factory()->for($user)->create();
    Step::factory()->for($user)->create();

    PendingSignInCode::factory()->for($user)->create();
}

it('has a factory for every model that saves a row', function () {
    foreach (allModels() as $model) {
        /** @var Model $row */
        $row = $model::factory()->create();

        expect($row->exists)->toBeTrue("{$model} factory did not save")
            ->and($model::query()->whereKey($row->getKey())->exists())->toBeTrue("{$model} row not found");
    }
});

it('ACC-005 deleting a user removes every row of theirs and nothing of anyone else', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    fillAccount($user);
    fillAccount($other);
    $library = Template::factory()->create();
    TemplateSlot::factory()->for($library)->create();

    $tables = collect(Schema::getTableListing(schemaQualified: false))
        ->filter(fn (string $table) => Schema::hasColumn($table, 'user_id'))
        ->reject(fn (string $table) => in_array($table, ['sessions', 'capabilities_approvals'], true))
        ->values();

    $user->delete();

    foreach ($tables as $table) {
        expect(DB::table($table)->where('user_id', $user->id)->count())->toBe(0, "{$table} kept the deleted user's rows")
            ->and(DB::table($table)->where('user_id', $other->id)->count())->toBeGreaterThan(0, "{$table} lost the other user's rows");
    }

    // Child rows with no user_id go with their parent.
    expect(FoodServing::count())->toBe(1)
        ->and(RecipeIngredient::count())->toBe(1)
        ->and(SavedMealFood::count())->toBe(1)
        ->and(DayTargetMeal::count())->toBe(1)
        ->and(TemplateSlot::count())->toBe(1);
});

it('ACC-005 every user_id column cascades on delete', function () {
    $owned = collect(Schema::getTableListing(schemaQualified: false))
        ->filter(fn (string $table) => Schema::hasColumn($table, 'user_id'))
        ->reject(fn (string $table) => in_array($table, ['sessions', 'capabilities_approvals'], true));

    expect($owned)->not->toBeEmpty();

    foreach ($owned as $table) {
        $key = collect(Schema::getForeignKeys($table))->first(fn (array $key) => $key['columns'] === ['user_id']);

        expect($key)->not->toBeNull("{$table}.user_id has no foreign key")
            ->and($key['foreign_table'])->toBe('users')
            ->and(strtolower($key['on_delete']))->toBe('cascade', "{$table}.user_id does not cascade");
    }
});

it('CAP-019 keys every row the outbox creates by a ULID and keeps a client-made one', function (string $model) {
    $row = $model::factory()->create();
    expect(Str::isUlid($row->getKey()))->toBeTrue("{$model} key is not a ULID");

    $ulid = strtolower((string) Str::ulid());
    $made = $model::factory()->create(['id' => $ulid]);

    expect($made->getKey())->toBe($ulid)
        ->and($model::query()->whereKey($ulid)->exists())->toBeTrue();
})->with([
    'workout sets' => WorkoutSet::class,
    'check-ins' => MealCheckIn::class,
    'weigh-ins' => WeighIn::class,
    'steps' => Step::class,
    'off-plan entries' => OffPlanEntry::class,
    'custom foods' => Food::class,
    'saved meals' => SavedMeal::class,
    'recipes' => Recipe::class,
]);

it('WT-020 keeps one step count per user per day, typed or from the bridge', function () {
    $user = User::factory()->create();
    Step::factory()->for($user)->create(['date' => '2026-10-12', 'count' => 8000, 'source' => MeasurementSource::Manual]);
    Step::factory()->for($user)->create(['date' => '2026-10-13', 'count' => 9000, 'source' => MeasurementSource::Shortcut]);
    Step::factory()->create(['date' => '2026-10-12']);

    expect($user->steps()->orderBy('date')->pluck('source')->all())
        ->toBe([MeasurementSource::Manual, MeasurementSource::Shortcut]);

    expect(fn () => Step::factory()->for($user)->create(['date' => '2026-10-12']))
        ->toThrow(QueryException::class);
});

it('WT-021 a weigh-in may carry a waist measurement in cm', function () {
    $plain = WeighIn::factory()->create();
    $waist = WeighIn::factory()->create(['waist_cm' => 81.5, 'source' => MeasurementSource::Shortcut]);

    expect($plain->fresh()->waist_cm)->toBeNull()
        ->and($waist->fresh()->waist_cm)->toBe(81.5)
        ->and($waist->fresh()->source)->toBe(MeasurementSource::Shortcut);
});

it('SET-001 stores body measures in metric beside the unit choice', function () {
    $profile = Profile::factory()->create([
        'weight_unit' => 'lb', 'height_unit' => 'ft_in', 'weight_kg' => 81.2, 'height_cm' => 180.3,
    ]);

    expect($profile->fresh()->only(['weight_unit', 'height_unit', 'weight_kg', 'height_cm']))
        ->toBe(['weight_unit' => 'lb', 'height_unit' => 'ft_in', 'weight_kg' => 81.2, 'height_cm' => 180.3]);
});

it('gives each meal of a day target an id and indexes it, dropping removed meals', function () {
    $day = DayTarget::factory()->create(['meals' => [['name' => 'Breakfast'], ['name' => 'Lunch']]]);
    [$breakfast, $lunch] = array_column($day->meals, 'id');

    expect($day->mealIndex()->orderBy('position')->pluck('id')->all())->toBe([$breakfast, $lunch]);

    $day->update(['meals' => [$day->meals[1]]]);

    expect($day->mealIndex()->pluck('id')->all())->toBe([$lunch]);
});
