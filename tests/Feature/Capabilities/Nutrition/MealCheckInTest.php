<?php

use App\Capabilities\Nutrition\MealReads;
use App\Capabilities\Reads\Today\TodayPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\MealPlanningState;
use App\Models\PlannedFood;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;

// The HTTP seam of meal_check_in. The rules are unit tests (tests/Unit/Actions/Meals/CheckInMealTest.php).

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function mealCheckInCall(?User $user, array $input, array $headers = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/meal_check_in', $input, ['Idempotency-Key' => (string) Str::ulid(), ...$headers]);
}

function mealCheckInUser(): User
{
    $user = User::factory()->create();
    SheetSeed::day($user);

    return $user;
}

it('CAP-011 CAP-013 registers meal_check_in on every surface, reversible, affecting the meal reads', function () {
    $definition = app(CapabilityRegistry::class)->get('meal_check_in');

    expect($definition->surfaces)->toBe(HostDefinition::SURFACES)
        ->and(HostDefinition::isReversible($definition))->toBeTrue()
        ->and(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe(MealReads::AFFECTS);
});

it('MEAL-040 refuses meal_check_in when signed out', function () {
    mealCheckInCall(null, ['meal_id' => SheetSeed::BREAKFAST])->assertUnauthorized();
});

it('CAP-020 answers 404 for another user\'s meal and checks nothing in', function () {
    mealCheckInUser();

    mealCheckInCall(User::factory()->create(), ['meal_id' => SheetSeed::BREAKFAST])->assertNotFound();

    expect(MealCheckIn::query()->count())->toBe(0);
});

it('MEAL-041 answers 422 naming the time when it is not "HH:MM"', function () {
    mealCheckInCall(mealCheckInUser(), ['meal_id' => SheetSeed::BREAKFAST, 'time' => '7pm'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'time');
});

it('MEAL-041 answers 422 naming an amount below 0, and saves nothing', function () {
    mealCheckInCall(mealCheckInUser(), ['meal_id' => SheetSeed::BREAKFAST, 'kcal' => -2000, 'protein_g' => 40, 'fat_g' => 18, 'carbs_g' => 80])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'kcal');

    expect(MealCheckIn::query()->count())->toBe(0);
});

it('OFF-030 checks in a meal of last week that the outbox replays days later, at the planned foods and the time the user acted', function () {
    $user = User::factory()->create();
    SheetSeed::day($user, '2026-09-15');
    $dinner = SheetSeed::id(SheetSeed::DINNER, '260915');
    SheetSeed::food($dinner);

    mealCheckInCall($user, ['meal_id' => $dinner], ['X-BB-Occurred-At' => '2026-09-16T19:20:00Z'])->assertOk();

    expect(MealCheckIn::query()->sole()->only(['meal_id', 'time', 'kcal', 'is_manual']))->toBe(['meal_id' => $dinner, 'time' => '19:20', 'kcal' => 247.5, 'is_manual' => false]);
});

it('MEAL-040 OFF-030 checks a meal in at the time the user acted, and names the meal reads it changed with an undo', function () {
    $user = mealCheckInUser();

    $data = mealCheckInCall($user, ['meal_id' => SheetSeed::DINNER], ['X-BB-Occurred-At' => '2026-09-23T10:00:00Z'])
        ->assertOk()
        ->json('data');

    expect(MealCheckIn::query()->sole()->only(['time', 'kcal', 'is_manual']))->toBe(['time' => '10:00', 'kcal' => 1120.0, 'is_manual' => false])
        ->and($data['invalidates'])->toBe(MealReads::keys(DayTargetMeal::query()->findOrFail(SheetSeed::DINNER)))
        ->and($data['undo'])->toBeString();
});

it('OFF-030 applies a check-in the outbox replays under the same idempotency key once, at its occurred-at time', function () {
    $user = mealCheckInUser();
    $headers = ['Idempotency-Key' => (string) Str::ulid(), 'X-BB-Occurred-At' => '2026-09-23T10:00:00Z'];

    $first = mealCheckInCall($user, ['meal_id' => SheetSeed::DINNER], $headers)->assertOk();
    $replay = mealCheckInCall($user, ['meal_id' => SheetSeed::DINNER], $headers)->assertOk()->assertJsonPath('meta.idempotent_replay', true);

    expect($replay->json('data'))->toEqual($first->json('data'))
        ->and(MealCheckIn::query()->sole()->time)->toBe('10:00');
});

it('MEAL-043 checks in a meal whose foods are within 5 g of each target, and meal_show shows it on track', function () {
    $user = mealCheckInUser();
    $food = Food::factory()->createOne(['kcal' => 690, 'protein' => 34, 'fat' => 16, 'carbs' => 104]);
    PlannedFood::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'food_id' => $food->getKey(), 'grams' => 100]);

    mealCheckInCall($user, ['meal_id' => SheetSeed::BREAKFAST])->assertOk();

    test()->actingAs($user)->postJson('/capabilities/meal_show', ['meal_id' => SheetSeed::BREAKFAST])
        ->assertOk()
        ->assertJsonPath('data.check_in.state', 'onTrack');
});

it('MEAL-045 refuses a one-tap check-in of a meal with the "Adjust foods" badge, and saves nothing', function () {
    $user = mealCheckInUser();
    SheetSeed::food(SheetSeed::BREAKFAST);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'state' => 'still_planning']);

    mealCheckInCall($user, ['meal_id' => SheetSeed::BREAKFAST])
        ->assertStatus(422)
        ->assertJsonPath('error.message', TodayPayload::ADJUST_FIRST);

    expect(MealCheckIn::query()->count())->toBe(0);
});
