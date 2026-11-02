<?php

use App\Capabilities\Reads\Shopping\PlannedShopping;
use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\ShoppingGroup;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The planned foods and recipes of a range of days, read from the meals (rp-clone ShoppingSource
// and the SHOP tests of ShoppingListModelTests). The rules are ShoppingListTest's; this reads the rows.
uses(RefreshDatabase::class);

function plannedShopMeal(User $user, string $date): DayTargetMeal
{
    return DayTarget::factory()->for($user)->create(['date' => $date, 'meals' => [['name' => 'Lunch']]])
        ->mealIndex()->firstOrFail();
}

function plannedShopFood(User $user, string $date, Food $food, float $grams, bool $cooked = false): void
{
    PlannedFood::factory()->create(['user_id' => $user->id, 'meal_id' => plannedShopMeal($user, $date)->id,
        'food_id' => $food->id, 'grams' => $grams, 'is_cooked' => $cooked]);
}

/** Two servings: 200 g chicken breast and 120 g rice, or what is given. */
function plannedShopRecipe(array $ingredients): Recipe
{
    $recipe = Recipe::factory()->create(['name' => 'Chicken bowl', 'servings' => 2.0]);
    foreach ($ingredients as $position => [$food, $grams, $cooked]) {
        RecipeIngredient::factory()->for($recipe)->create(['food_id' => $food->id, 'position' => $position, 'grams' => $grams, 'is_cooked' => $cooked]);
    }

    return $recipe;
}

function plannedShopServings(User $user, string $date, Recipe $recipe, float $servings): void
{
    PlannedRecipe::factory()->create(['user_id' => $user->id, 'meal_id' => plannedShopMeal($user, $date)->id,
        'recipe_id' => $recipe->id, 'servings' => $servings]);
}

/** @return array<string, list<string>> category => "name: grams" */
function plannedShopRows(User $user, string $from = '2026-10-10', string $to = '2026-10-16'): array
{
    $utc = new DateTimeZone('UTC');
    $groups = PlannedShopping::groups($user, new DateRange(new DateTimeImmutable($from, $utc), new DateTimeImmutable($to, $utc)));

    $rows = [];
    foreach ($groups as $group) {
        /** @var ShoppingGroup $group */
        $rows[$group->category->value] = array_map(fn ($item) => "{$item->food->name}: {$item->grams}", $group->items);
    }

    return $rows;
}

it('SHOP-001 builds the list from the foods planned on the days of the range, both ends included', function () {
    $user = User::factory()->create();
    plannedShopFood($user, '2026-10-09', Food::factory()->create(['name' => 'Banana']), 100);
    plannedShopFood($user, '2026-10-10', Food::factory()->create(['name' => 'Broccoli']), 200);
    plannedShopFood($user, '2026-10-16', Food::factory()->create(['name' => 'Spinach']), 100);
    plannedShopFood($user, '2026-10-17', Food::factory()->create(['name' => 'Carrot']), 100);

    expect(plannedShopRows($user))->toBe(['produce' => ['Broccoli: 200', 'Spinach: 100']])
        ->and(plannedShopRows($user, '2026-10-12', '2026-10-17'))->toBe(['produce' => ['Carrot: 100', 'Spinach: 100']]);
});

it('SHOP-001 reads only the user\'s own days, and not a day that was deleted', function () {
    $user = User::factory()->create();
    plannedShopFood(User::factory()->create(), '2026-10-11', Food::factory()->create(['name' => 'Their broccoli']), 200);
    plannedShopFood($user, '2026-10-12', Food::factory()->create(['name' => 'Spinach']), 100);
    $deleted = DayTarget::factory()->for($user)->create(['date' => '2026-10-13', 'meals' => [['name' => 'Lunch']]]);
    PlannedFood::factory()->create(['user_id' => $user->id, 'meal_id' => $deleted->mealIndex()->firstOrFail()->id,
        'food_id' => Food::factory()->create(['name' => 'Kale'])->id]);
    $deleted->delete();

    expect(plannedShopRows($user))->toBe(['produce' => ['Spinach: 100']]);
});

it('SHOP-001 has no groups when nothing is planned', function () {
    expect(plannedShopRows(User::factory()->create()))->toBe([]);
});

it('SHOP-002 breaks a planned recipe into its ingredients for the servings planned', function () {
    $user = User::factory()->create();
    $chicken = Food::factory()->create(['name' => 'Chicken breast']);
    $rice = Food::factory()->create(['name' => 'Rice']);
    plannedShopServings($user, '2026-10-11', plannedShopRecipe([[$chicken, 200, false], [$rice, 120, false]]), 1);

    // One of the recipe's two servings is half of each ingredient.
    expect(plannedShopRows($user))->toBe(['protein' => ['Chicken breast: 100'], 'grainsAndBread' => ['Rice: 60']]);
});

it('SHOP-002 adds recipe ingredients to the same food planned on its own', function () {
    $user = User::factory()->create();
    $chicken = Food::factory()->create(['name' => 'Chicken breast']);
    plannedShopFood($user, '2026-10-11', $chicken, 150);
    plannedShopServings($user, '2026-10-12', plannedShopRecipe([[$chicken, 200, false]]), 2);

    // 150 g + the whole recipe's 200 g.
    expect(plannedShopRows($user))->toBe(['protein' => ['Chicken breast: 350']]);
});

it('SHOP-002 still breaks down a recipe the user has since deleted', function () {
    $user = User::factory()->create();
    $recipe = plannedShopRecipe([[Food::factory()->create(['name' => 'Broccoli']), 200, false]]);
    plannedShopServings($user, '2026-10-11', $recipe, 2);
    $recipe->delete();

    expect(plannedShopRows($user))->toBe(['produce' => ['Broccoli: 200']]);
});

it('SHOP-003 takes a food\'s category from its name, then its curated group, else other', function () {
    $user = User::factory()->create();
    plannedShopFood($user, '2026-10-10', Food::factory()->create(['name' => 'Greek yogurt, nonfat', 'curated_group' => 'protein_recommended']), 200);
    plannedShopFood($user, '2026-10-10', Food::factory()->create(['name' => 'Whey protein', 'curated_group' => 'protein_recommended']), 100);
    plannedShopFood($user, '2026-10-10', Food::factory()->create(['name' => 'Mystery spread', 'curated_group' => 'fat_sparingly']), 50);
    plannedShopFood($user, '2026-10-10', Food::factory()->create(['name' => 'Xanthan gum']), 10);

    expect(plannedShopRows($user))->toBe([
        'protein' => ['Whey protein: 100'],
        'dairy' => ['Greek yogurt, nonfat: 200'],
        'pantry' => ['Mystery spread: 50'],
        'other' => ['Xanthan gum: 10'],
    ]);
});

it('SHOP-003 sums a food across days and rounds it up to a friendly amount', function () {
    $user = User::factory()->create();
    $yogurt = Food::factory()->create(['name' => 'Greek yogurt']);
    plannedShopFood($user, '2026-10-10', $yogurt, 120);
    plannedShopFood($user, '2026-10-12', $yogurt, 120);

    expect(plannedShopRows($user))->toBe(['dairy' => ['Greek yogurt: 250']]);
});

it('SHOP-004 buys cooked amounts by their raw weight, on their own or in a recipe', function () {
    $user = User::factory()->create();
    $chicken = Food::factory()->create(['name' => 'Chicken breast', 'cooked_ratio' => 0.75]);
    $rice = Food::factory()->create(['name' => 'Rice', 'cooked_ratio' => 3.0]);
    plannedShopFood($user, '2026-10-10', $chicken, 300, cooked: true);
    plannedShopFood($user, '2026-10-10', Food::factory()->create(['name' => 'Oats']), 80);
    // Two servings make 300 g of cooked rice; one serving planned is 150 g cooked, 50 g raw.
    plannedShopServings($user, '2026-10-11', plannedShopRecipe([[$rice, 300, true]]), 1);

    expect(plannedShopRows($user))->toBe(['protein' => ['Chicken breast: 400'], 'grainsAndBread' => ['Oats: 80', 'Rice: 50']]);
});
