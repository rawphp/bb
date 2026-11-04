<?php

use App\Models\DayTarget;
use App\Models\Food;
use App\Models\FoodServing;
use App\Models\MealPlanningState;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\ScreenFixture;
use Tests\Support\TodaySeed;

// The HTTP seam of meal_show and its screen fixtures. The rules are unit tests (MealPayloadTest).

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

/** @return list<string> the ids of a user's meals on a day, in their stored order */
function mealShowIds(User $user, string $date): array
{
    return array_column(DayTarget::query()->where('user_id', $user->id)->whereDate('date', $date)->firstOrFail()->meals, 'id');
}

function mealShowUser(): User
{
    $user = TodaySeed::user();
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, TodaySeed::mesocycle($user));

    return $user;
}

function mealShowChicken(): Food
{
    $chicken = Food::factory()->createOne(['name' => 'Chicken breast', 'kcal' => 120, 'protein' => 23, 'fat' => 2, 'carbs' => 0]);
    FoodServing::factory()->create(['food_id' => $chicken->getKey(), 'label' => 'fillet', 'grams' => 150]);

    return $chicken;
}

it('MEAL-001 refuses meal_show when signed out', function () {
    $this->postJson('/capabilities/meal_show', ['meal_id' => 'any'])->assertUnauthorized();
});

it('CAP-020 answers 404 for another user\'s meal', function () {
    $theirs = mealShowIds(TodaySeed::stranger(), '2026-09-23')[0];

    $this->actingAs(mealShowUser())->postJson('/capabilities/meal_show', ['meal_id' => $theirs])->assertNotFound();
});

it('MEAL-001 answers 422 naming meal_id when it is missing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/meal_show', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'meal_id');
});

it('MEAL-001 MEAL-010 RCP-002 shows a seeded meal with foods, a recipe and the post-workout tag, with the clock fixed', function () {
    $user = mealShowUser();
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-23')->firstOrFail();
    $meals = $day->meals;
    $meals[2]['time'] = '18:30';
    $day->update(['meals' => $meals]);
    $dinner = $meals[2]['id'];
    $planned = PlannedFood::factory()->create(['meal_id' => $dinner, 'food_id' => mealShowChicken()->getKey(), 'grams' => 296, 'position' => 1, 'is_locked' => true]);

    $data = $this->actingAs($user)->postJson('/capabilities/meal_show', ['meal_id' => $dinner])->assertOk()->json('data');

    assertMatchesScreenFixture('meal_show', $data);
    expect($data['meal_id'])->toBe($dinner)
        ->and($data['title'])->toBe('Dinner')
        ->and(array_column($data['entries'], 'name'))->toBe(['Chili', 'Chicken breast'])
        ->and($data['entries'][1]['id'])->toBe((string) $planned->getKey())
        ->and($data['entries'][1]['amount_text'])->toBe('296 g (about 2 fillets)')
        ->and($data['entries'][1]['is_locked'])->toBeTrue()
        ->and($data['entries'][0]['amount_text'])->toBe('2 servings')
        ->and($data['peri_workout']['detail'])->toBe('Workout 5:30 PM')
        ->and($data['check_in']['state'])->toBe('notCheckedIn')
        ->and(count($data['menu']))->toBe(10);
});

it('MEAL-046 shows an empty meal in the shape of its empty fixture', function () {
    $user = mealShowUser();

    $data = $this->actingAs($user)->postJson('/capabilities/meal_show', ['meal_id' => mealShowIds($user, '2026-09-24')[0]])->assertOk()->json('data');

    assertMatchesScreenFixture('meal_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('meal_show.empty'), $data))->toBe([])
        ->and($data['entries'])->toBe([])
        ->and($data['empty']['title'])->toBe('Your meal is empty')
        ->and(array_column($data['bars'], 'value_text'))->toBe(['0/780', '0/51', '0/21', '0/96']);
});

it('MEAL-007 DAY-003 shows a meal still being planned that is short of carbs in the shape of its short fixture', function () {
    $user = mealShowUser();
    $dinner = mealShowIds($user, '2026-09-24')[2];
    $oil = Food::factory()->createOne(['name' => 'Olive oil', 'kcal' => 884, 'protein' => 0, 'fat' => 100, 'carbs' => 0]);
    PlannedFood::factory()->create(['meal_id' => $dinner, 'food_id' => mealShowChicken()->getKey(), 'grams' => 296, 'position' => 0]);
    PlannedFood::factory()->create(['meal_id' => $dinner, 'food_id' => $oil->getKey(), 'grams' => 22, 'position' => 1]);
    MealPlanningState::factory()->create(['meal_id' => $dinner, 'state' => 'still_planning']);

    $data = $this->actingAs($user)->postJson('/capabilities/meal_show', ['meal_id' => $dinner])->assertOk()->json('data');

    assertMatchesScreenFixture('meal_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('meal_show.short'), $data))->toBe([])
        ->and($data['adjust_badge'])->toBe(['text' => 'Adjust foods', 'tone' => 'warning'])
        ->and($data['hints'])->toBe([['text' => 'Short 128 g carbs. Add a food from Carbs · Recommended.', 'group' => 'carbs_recommended']])
        ->and(array_column($data['menu'], 'is_enabled', 'item')['check_in_meal'])->toBeFalse();
});

it('CAP-019 MEAL-001 RCP-002 gives a food entry and a recipe entry the row\'s own ULID, with no kind prefix', function () {
    $user = mealShowUser();
    $dinner = mealShowIds($user, '2026-09-23')[2];
    $food = PlannedFood::factory()->create(['meal_id' => $dinner, 'food_id' => mealShowChicken()->getKey(), 'position' => 5]);
    $recipe = PlannedRecipe::query()->where('meal_id', $dinner)->firstOrFail();

    $entries = collect($this->actingAs($user)->postJson('/capabilities/meal_show', ['meal_id' => $dinner])->assertOk()->json('data.entries'))->keyBy('kind');

    expect(Str::isUlid($food->getKey()))->toBeTrue()
        ->and($entries['food']['id'])->toBe($food->getKey())
        ->and($entries['recipe']['id'])->toBe($recipe->getKey())
        ->and(Str::isUlid($entries['recipe']['id']))->toBeTrue();
});

it('MEAL-061 an empty meal offers the user\'s own same meal from the 28 days before, and meal_copy fills it from one', function () {
    $user = mealShowUser();
    $chicken = mealShowChicken();
    $dinner = fn (User $owner, string $date) => mealShowIds($owner, $date)[2];
    $plan = fn (string $mealId) => PlannedFood::factory()->create(['meal_id' => $mealId, 'food_id' => $chicken->getKey(), 'grams' => 200]);
    foreach (['2026-08-26', '2026-08-27'] as $date) {
        DayTarget::factory()->for($user)->create([
            'date' => $date, 'day_type' => 'hard', 'kcal' => 2600, 'protein_g' => 170, 'fat_g' => 70, 'carbs_g' => 320,
            'meals' => [
                ['name' => 'Breakfast', 'kcal' => 780, 'protein_g' => 51, 'fat_g' => 21, 'carbs_g' => 96],
                ['name' => 'Lunch', 'kcal' => 780, 'protein_g' => 51, 'fat_g' => 21, 'carbs_g' => 96],
                ['name' => 'Dinner', 'kcal' => 1040, 'protein_g' => 68, 'fat_g' => 28, 'carbs_g' => 128],
            ],
        ]);
        $plan($dinner($user, $date));
    }
    // After the meal's day, and another user's: neither is offered.
    $plan($dinner($user, '2026-09-25'));
    $plan($dinner(TodaySeed::stranger(), '2026-09-22'));
    $thursday = $dinner($user, '2026-09-24');

    $data = $this->actingAs($user)->postJson('/capabilities/meal_show', ['meal_id' => $thursday])->assertOk()->json('data');

    assertMatchesScreenFixture('meal_show', $data);
    // Wednesday's dinner holds TodaySeed's chili; 26 Aug is 29 days before.
    expect($data['empty']['repeats'])->toBe([
        ['meal_id' => $dinner($user, '2026-09-23'), 'title' => 'Wed 23 Sep', 'foods_text' => 'Chili'],
        ['meal_id' => $dinner($user, '2026-08-27'), 'title' => 'Thu 27 Aug', 'foods_text' => 'Chicken breast'],
    ]);

    $this->postJson('/capabilities/meal_copy', ['meal_id' => $data['empty']['repeats'][1]['meal_id'], 'target_meal_ids' => [$thursday]])->assertOk();

    expect(array_column($this->postJson('/capabilities/meal_show', ['meal_id' => $thursday])->json('data.entries'), 'name'))->toBe(['Chicken breast']);
});
