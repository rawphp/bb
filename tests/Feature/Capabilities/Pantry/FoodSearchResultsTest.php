<?php

use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\FoodRecent;
use App\Models\PlannedFood;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// food_search with a query, against the full database. InnoDB indexes full-text only on commit, so these
// tests commit the test's transaction, write for real, and remove their rows afterwards (as FoodSearchTest does).

beforeEach(function () {
    Carbon::setTestNow('2026-11-03 09:00:00');
    DB::commit();
    $this->usersBefore = (int) User::query()->max('id');
    $this->user = User::factory()->create();
});

afterEach(function () {
    User::query()->where('id', '>', $this->usersBefore)->delete();
    DB::table('foods')->delete();
    DB::beginTransaction();
    Carbon::setTestNow();
});

it('FOOD-023 FOOD-028 FOOD-062 lists recents, favourites and curated matches first, then More results, and offers a custom food', function () {
    $soup = Food::factory()->create(['name' => 'Chicken noodle soup', 'brand' => "Campbell's", 'kind' => 'packaged']);
    FoodRecent::factory()->create(['user_id' => $this->user->id, 'food_id' => $soup->id, 'used_at' => '2026-11-02 08:00:00']);
    $breast = Food::factory()->create(['name' => 'Chicken breast', 'source' => 'curated', 'curated' => true, 'curated_group' => 'protein_recommended', 'cooked_ratio' => 0.75]);
    FoodFavorite::factory()->create(['user_id' => $this->user->id, 'food_id' => $breast->id]);
    $stock = Food::factory()->create(['name' => 'Chicken stock']);
    FoodFavorite::factory()->create(['user_id' => $this->user->id, 'food_id' => $stock->id]);
    Food::factory()->custom($this->user)->create(['name' => 'Chicken shake']);
    $nuggets = Food::factory()->create(['name' => 'Chicken nuggets', 'brand' => 'Generic']);
    $other = User::factory()->create();
    FoodRecent::factory()->create(['user_id' => $other->id, 'food_id' => $nuggets->id, 'used_at' => '2026-11-02 08:00:00']);
    FoodFavorite::factory()->create(['user_id' => $other->id, 'food_id' => $nuggets->id]);
    Food::factory()->create(['name' => 'Chicken wings']);
    Food::factory()->create(['name' => 'Rice']);
    $meal = DayTargetMeal::factory()->create(['day_target_id' => DayTarget::factory()->for($this->user)->state(['meals' => []])]);
    PlannedFood::factory()->create(['meal_id' => $meal->id, 'food_id' => $nuggets->id]);

    $data = $this->actingAs($this->user)
        ->postJson('/capabilities/food_search', ['query' => 'chicken', 'meal_id' => $meal->id])
        ->assertOk()->json('data');

    assertMatchesScreenFixture('food_search', $data);
    expect(array_column($data['sections'], 'title'))->toBe([null, 'More results'])
        ->and(array_column($data['sections'][0]['rows'], 'name'))->toBe(['Chicken noodle soup', 'Chicken breast', 'Chicken stock', 'Chicken shake'])
        ->and(array_column($data['sections'][1]['rows'], 'name'))->toEqualCanonicalizing(['Chicken wings', 'Chicken nuggets'])
        ->and(array_column($data['sections'][0]['rows'], 'is_favorite'))->toBe([false, true, true, false])
        ->and(array_column($data['sections'][1]['rows'], 'is_favorite'))->toBe([false, false])
        ->and(array_column($data['sections'][1]['rows'], 'is_in_meal', 'name'))->toEqual(['Chicken wings' => false, 'Chicken nuggets' => true])
        ->and($data['already_in_meal'])->toBe([['food_id' => $nuggets->id, 'name' => 'Chicken nuggets']])
        ->and($data['create_custom_food'])->toBe(['title' => "Can't find it? Create a custom food", 'name' => 'chicken']);
});

it('FOOD-025 FOOD-026 FOOD-027 narrows a search by source, curated group and the user\'s diet filters', function () {
    Profile::factory()->for($this->user)->create(['diet_filters' => ['vegan']]);
    Food::factory()->create(['name' => 'Oat bar', 'kind' => 'packaged', 'vegan' => true]);
    Food::factory()->create(['name' => 'Oat burger', 'kind' => 'restaurant', 'vegan' => true]);
    Food::factory()->create(['name' => 'Oat porridge', 'source' => 'curated', 'curated' => true, 'curated_group' => 'carbs_recommended', 'vegan' => true]);
    Food::factory()->create(['name' => 'Oat milk latte', 'kind' => 'packaged']);

    $sections = fn (array $input) => array_map(
        fn (array $s) => [$s['id'] => array_column($s['rows'], 'name')],
        $this->actingAs($this->user)->postJson('/capabilities/food_search', ['query' => 'oat'] + $input)->assertOk()->json('data.sections'),
    );

    expect($sections(['source' => 'packaged']))->toBe([['more' => ['Oat bar']]])
        ->and($sections(['source' => 'restaurants']))->toBe([['more' => ['Oat burger']]])
        ->and($sections(['group' => 'carbs_recommended']))->toBe([['matches' => ['Oat porridge']]]);
});

it('FOOD-029 a search costs the same few queries however many foods it finds', function () {
    $count = function (int $foods): int {
        foreach (range(1, $foods) as $n) {
            Food::factory()->create(['name' => "Lentil soup {$n}"]);
            Food::factory()->create(['name' => "Lentils curated {$n}", 'source' => 'curated', 'curated' => true, 'curated_group' => 'carbs_recommended']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user->fresh())->postJson('/capabilities/food_search', ['query' => 'lentil'])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        DB::table('foods')->delete();

        return $queries;
    };

    expect($count(2))->toBe($count(12));
});
