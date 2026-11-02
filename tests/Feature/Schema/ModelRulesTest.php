<?php

use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\MealPlanningState;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\OffPlanEntry;
use App\Models\Phase;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Puts a check-in, a planned food, a planned recipe and a planning state on the meal. */
function fillMeal(string $mealId, int $userId): void
{
    $on = ['meal_id' => $mealId, 'user_id' => $userId];
    MealCheckIn::factory()->create($on);
    PlannedFood::factory()->create($on);
    PlannedRecipe::factory()->create($on);
    MealPlanningState::factory()->create($on);
}

/** @return array<string, int> */
function mealChildren(string $mealId): array
{
    return collect(['meal_check_ins', 'planned_foods', 'planned_recipes', 'meal_planning_states'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->where('meal_id', $mealId)->count()])
        ->all();
}

const MEAL_HAS_ONE_OF_EACH = ['meal_check_ins' => 1, 'planned_foods' => 1, 'planned_recipes' => 1, 'meal_planning_states' => 1];
const MEAL_HAS_NONE = ['meal_check_ins' => 0, 'planned_foods' => 0, 'planned_recipes' => 0, 'meal_planning_states' => 0];

it('ACC-005 deleting a user is all or nothing: a failed delete keeps the rows it deleted early', function () {
    $user = User::factory()->create();
    WorkoutSession::factory()->for($user)->create();
    OffPlanEntry::factory()->for($user)->create();
    Recipe::factory()->for($user)->create();
    Mesocycle::factory()->for($user)->create();

    User::deleted(fn () => throw new RuntimeException('the delete failed'));

    expect(fn () => $user->delete())->toThrow(RuntimeException::class);

    expect(User::whereKey($user->id)->exists())->toBeTrue();
    foreach (['workout_sessions', 'off_plan_entries', 'recipes', 'mesocycles'] as $table) {
        expect(DB::table($table)->where('user_id', $user->id)->count())->toBe(1, "{$table} lost its row");
    }
});

it('keeps a meal\'s foods, check-in and planning state when the meals are reordered', function () {
    $day = DayTarget::factory()->create(['meals' => [['name' => 'Breakfast'], ['name' => 'Lunch']]]);
    [$breakfast, $lunch] = array_column($day->meals, 'id');
    fillMeal($breakfast, $day->user_id);

    $day->update(['meals' => array_reverse($day->meals)]);

    expect(mealChildren($breakfast))->toBe(MEAL_HAS_ONE_OF_EACH)
        ->and($day->mealIndex()->orderBy('position')->pluck('id')->all())->toBe([$lunch, $breakfast]);
});

it('removing a meal drops only that meal\'s foods, check-in and planning state', function () {
    $day = DayTarget::factory()->create(['meals' => [['name' => 'Breakfast'], ['name' => 'Lunch']]]);
    [$breakfast, $lunch] = array_column($day->meals, 'id');
    fillMeal($breakfast, $day->user_id);
    fillMeal($lunch, $day->user_id);

    $day->update(['meals' => [$day->meals[1]]]);

    expect(mealChildren($breakfast))->toBe(MEAL_HAS_NONE)
        ->and(mealChildren($lunch))->toBe(MEAL_HAS_ONE_OF_EACH);
});

it('refuses a meal with no id when a day is updated, and changes nothing', function (array $meals) {
    $day = DayTarget::factory()->create(['meals' => [['name' => 'Breakfast'], ['name' => 'Lunch']]]);
    $before = $day->meals;
    [$breakfast, $lunch] = array_column($before, 'id');
    fillMeal($breakfast, $day->user_id);

    $sent = array_map(fn ($meal) => is_int($meal) ? $before[$meal] : $meal, $meals);
    $index = array_search(true, array_map(fn ($meal) => ! isset($meal['id']), $sent), true);

    expect(fn () => $day->update(['meals' => $sent]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey("meals.{$index}.id"));

    expect(array_column($day->fresh()->meals, 'id'))->toBe([$breakfast, $lunch])
        ->and($day->mealIndex()->orderBy('position')->pluck('id')->all())->toBe([$breakfast, $lunch])
        ->and(mealChildren($breakfast))->toBe(MEAL_HAS_ONE_OF_EACH);
})->with([
    'no meal has an id' => [[['name' => 'Breakfast', 'kcal' => 600], ['name' => 'Lunch']]],
    'a meal with no id beside meals with ids' => [[1, ['name' => 'Snack']]],
]);

it('adds a meal to a day when it brings its own id', function () {
    $day = DayTarget::factory()->create(['meals' => [['name' => 'Breakfast']]]);
    $breakfast = $day->meals[0]['id'];
    fillMeal($breakfast, $day->user_id);
    $snack = (string) Str::uuid7();

    $day->update(['meals' => [...$day->meals, ['id' => $snack, 'name' => 'Snack']]]);

    expect($day->mealIndex()->orderBy('position')->pluck('id')->all())->toBe([$breakfast, $snack])
        ->and(mealChildren($breakfast))->toBe(MEAL_HAS_ONE_OF_EACH);
});

it('a meal holds one check-in: a second one for the same meal is refused', function () {
    $day = DayTarget::factory()->create(['meals' => [['name' => 'Breakfast'], ['name' => 'Lunch']]]);
    [$breakfast, $lunch] = array_column($day->meals, 'id');
    $on = fn (string $meal) => ['meal_id' => $meal, 'user_id' => $day->user_id];
    MealCheckIn::factory()->create($on($breakfast));

    expect(fn () => DB::transaction(fn () => MealCheckIn::factory()->create($on($breakfast))))
        ->toThrow(QueryException::class);

    MealCheckIn::factory()->create($on($lunch));
    expect(MealCheckIn::where('meal_id', $breakfast)->count())->toBe(1)
        ->and(MealCheckIn::where('meal_id', $lunch)->count())->toBe(1);
});

it('a soft-deleted day keeps its meals for a restore, and a force delete removes them', function () {
    $day = DayTarget::factory()->create();
    $meal = $day->meals[0]['id'];
    fillMeal($meal, $day->user_id);

    $day->delete();
    expect(DayTargetMeal::whereKey($meal)->exists())->toBeTrue()
        ->and(mealChildren($meal))->toBe(MEAL_HAS_ONE_OF_EACH);

    $day->restore();
    expect($day->fresh()->mealIndex()->pluck('id')->all())->toBe([$meal])
        ->and(mealChildren($meal))->toBe(MEAL_HAS_ONE_OF_EACH);

    $day->forceDelete();
    expect(DayTargetMeal::whereKey($meal)->exists())->toBeFalse()
        ->and(mealChildren($meal))->toBe(MEAL_HAS_NONE);
});

it('keeps a meal id it is sent, lower-cased, and indexes it', function () {
    $id = '0199D1F0-0000-7000-8000-0000000000AA';
    $day = DayTarget::factory()->create(['meals' => [['id' => $id, 'name' => 'Breakfast']]]);

    expect($day->fresh()->meals[0]['id'])->toBe(strtolower($id))
        ->and($day->mealIndex()->pluck('id')->all())->toBe([strtolower($id)]);
});

it('refuses a meal id another day target uses, and leaves that day\'s meal alone', function (bool $sameUser) {
    $first = DayTarget::factory()->create(['meals' => [['name' => 'Breakfast'], ['name' => 'Lunch']]]);
    $taken = $first->meals[1]['id'];
    $owner = $sameUser ? $first->user : User::factory()->create();

    $save = fn () => DayTarget::factory()->for($owner)->create(['date' => '2026-10-14', 'meals' => [['id' => strtoupper($taken)]]]);

    expect($save)->toThrow(ValidationException::class, 'already used');

    $row = DayTargetMeal::findOrFail($taken);
    expect($row->day_target_id)->toBe($first->id)
        ->and($row->user_id)->toBe($first->user_id)
        ->and($row->position)->toBe(1)
        ->and(DayTarget::count())->toBe(1);
})->with(['the same user' => true, 'another user' => false]);

it('saves nothing when another writer takes a meal id between the check and the index write', function () {
    $first = DayTarget::factory()->create();
    $id = '0199d1f0-0000-7000-8000-0000000000cc';

    // A concurrent save indexes the same id under the first day after this save's check has passed.
    DayTarget::saving(function (DayTarget $target) use ($first, $id) {
        if ($target->isNot($first)) {
            DayTargetMeal::insert(['id' => $id, 'user_id' => $first->user_id, 'day_target_id' => $first->id, 'position' => 5]);
        }
    });

    expect(fn () => DayTarget::factory()->for($first->user)->create(['date' => '2026-10-14', 'meals' => [['id' => $id]]]))
        ->toThrow(QueryException::class);

    // The simulated writer ran inside this save's transaction, so its row rolls back with the rest.
    expect(DayTarget::count())->toBe(1)
        ->and(DayTargetMeal::pluck('day_target_id')->all())->toBe([$first->id]);
});

it('refuses the meal id of a soft-deleted day', function () {
    $first = DayTarget::factory()->create();
    $taken = $first->meals[0]['id'];
    $first->delete();

    expect(fn () => DayTarget::factory()->for($first->user)->create(['meals' => [['id' => $taken]]]))
        ->toThrow(ValidationException::class);
});

it('refuses the same meal id twice in one day', function () {
    $id = '0199d1f0-0000-7000-8000-0000000000bb';

    expect(fn () => DayTarget::factory()->create(['meals' => [['id' => $id], ['id' => $id]]]))
        ->toThrow(ValidationException::class, 'already used');
    expect(DayTargetMeal::count())->toBe(0);
});

it('a soft-deleted phase hides from its mesocycle and comes back on restore; a force delete unlinks it', function () {
    $phase = Phase::factory()->create();
    $mesocycle = Mesocycle::factory()->for($phase->user)->create(['phase_id' => $phase->id]);
    $other = Mesocycle::factory()->create(['phase_id' => Phase::factory()]);

    $phase->delete();
    expect($mesocycle->fresh()->phase)->toBeNull();

    $phase->restore();
    expect($mesocycle->fresh()->phase?->id)->toBe($phase->id);

    $phase->forceDelete();
    expect($mesocycle->fresh()->phase_id)->toBeNull()
        ->and($other->fresh()->phase_id)->not->toBeNull();
});

it('FOOD-060 EX-004 shows the library and the user\'s own custom foods and exercises, never another user\'s', function (string $model) {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $library = $model::factory()->create(['name' => 'Library']);
    $mine = $model::factory()->custom($b)->create(['name' => 'Mine']);
    $model::factory()->custom($a)->create(['name' => 'Theirs']);

    expect($model::visibleTo($b)->orderBy('name')->pluck('id')->all())->toBe([$library->id, $mine->id])
        // Combined with another where, the owner check stays grouped.
        ->and($model::visibleTo($b)->where('name', 'Theirs')->exists())->toBeFalse()
        ->and($model::where('name', '!=', 'Library')->visibleTo($b)->pluck('id')->all())->toBe([$mine->id]);
})->with(['foods' => Food::class, 'exercises' => Exercise::class]);

it('RCP-020 lists the library or one user\'s recipes, by meal type and every diet asked for', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $veganDinner = Recipe::factory()->create(['meal_type' => 'dinner', 'vegan' => true]);
    Recipe::factory()->create(['meal_type' => 'dinner', 'vegan' => false]);
    Recipe::factory()->create(['meal_type' => 'lunch', 'vegan' => true]);
    $mine = Recipe::factory()->for($a)->create();
    Recipe::factory()->for($b)->create();

    expect(Recipe::ownedBy(null)->count())->toBe(3)
        ->and(Recipe::ownedBy($a)->pluck('id')->all())->toBe([$mine->id])
        ->and(Recipe::ownedBy(null)->filtered('dinner', ['vegan', 'bogus'])->pluck('id')->all())->toBe([$veganDinner->id])
        ->and(Recipe::ownedBy(null)->filtered(null, [])->count())->toBe(3);
});

it('FOOD-060 does not take a food\'s, exercise\'s or recipe\'s owner or source from mass assignment', function () {
    $user = User::factory()->create();

    $food = new Food(['name' => 'Oats', 'user_id' => $user->id, 'source' => 'usda', 'source_id' => '1', 'kind' => 'generic', 'curated' => true]);
    $exercise = new Exercise(['name' => 'Row', 'user_id' => $user->id]);
    $recipe = new Recipe(['name' => 'Stew', 'user_id' => $user->id]);

    expect($food->name)->toBe('Oats')
        ->and(array_keys($food->getAttributes()))->toBe(['name'])
        ->and($exercise->user_id)->toBeNull()
        ->and($recipe->user_id)->toBeNull();
});

it('stores a session time sent with an offset as the same instant in the app timezone', function () {
    $session = WorkoutSession::factory()->create([
        'actual_start_at' => '2026-10-12T09:00:00+10:00',
        'actual_end_at' => null,
    ]);

    $raw = DB::table('workout_sessions')->where('id', $session->id)->first();
    expect(config('app.timezone'))->toBe('UTC')
        ->and($raw->actual_start_at)->toBe('2026-10-11 23:00:00')
        ->and($raw->actual_end_at)->toBeNull();
});

it('stores the shared domain enums\' values', function () {
    $exercise = Exercise::factory()->create([
        'muscle_group' => MuscleGroup::SideDelts, 'equipment' => Equipment::EzBar, 'load_type' => ExerciseLoadType::BodyweightPlusLoad,
    ]);
    $phase = Phase::factory()->create(['type' => PhaseType::MiniCut]);

    expect((array) DB::table('exercises')->where('id', $exercise->id)->first(['muscle_group', 'equipment', 'load_type']))
        ->toBe(['muscle_group' => 'sideDelts', 'equipment' => 'ezBar', 'load_type' => 'bodyweightPlusLoad'])
        ->and(DB::table('phases')->where('id', $phase->id)->value('type'))->toBe('miniCut')
        ->and($exercise->fresh()->muscle_group)->toBe(MuscleGroup::SideDelts);
});

it('reaches a user\'s, session\'s, exercise\'s and recipe\'s rows from the parent, and eager-loads them', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $rows = fn (User $owner) => [
        'dayTargets' => DayTarget::factory()->for($owner)->create(),
        'phases' => Phase::factory()->for($owner)->create(),
        'mesocycles' => Mesocycle::factory()->for($owner)->create(),
        'workoutSessions' => WorkoutSession::factory()->for($owner)->create(),
        'foods' => Food::factory()->custom($owner)->create(),
        'recipes' => Recipe::factory()->for($owner)->create(),
    ];
    $mine = $rows($user);
    $rows($other);

    $loaded = User::with(array_keys($mine))->findOrFail($user->id);
    foreach ($mine as $relation => $row) {
        expect($loaded->relationLoaded($relation))->toBeTrue()
            ->and($loaded->{$relation}->modelKeys())->toBe([$row->getKey()], "{$relation} is not the user's own rows");
    }

    $session = $mine['workoutSessions'];
    $exercise = Exercise::factory()->create();
    $muscle = MuscleFeedback::factory()->create(['workout_session_id' => $session->id]);
    $feedback = ExerciseFeedback::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id]);
    $set = WorkoutSet::factory()->create(['workout_session_id' => $session->id, 'exercise_id' => $exercise->id]);
    $planned = PlannedRecipe::factory()->create(['recipe_id' => $mine['recipes']->id]);
    MuscleFeedback::factory()->create();
    ExerciseFeedback::factory()->create();
    WorkoutSet::factory()->create();
    PlannedRecipe::factory()->create();

    expect($session->muscleFeedback->modelKeys())->toBe([$muscle->id])
        ->and($session->exerciseFeedback->modelKeys())->toBe([$feedback->id])
        ->and($exercise->sets->modelKeys())->toBe([$set->id])
        ->and($mine['recipes']->plannedRecipes->modelKeys())->toBe([$planned->id]);
});
