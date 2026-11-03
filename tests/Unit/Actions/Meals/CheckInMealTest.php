<?php

use App\Actions\Meals\CheckInMeal;
use App\Capabilities\Meal\Data\MealCheckInInput;
use App\Capabilities\Reads\Today\TodayPayload;
use App\Domain\MealFit\MealFit;
use App\Domain\Model\MealCheckInState;
use App\Domain\Model\MealTarget;
use App\Models\Food;
use App\Models\MealCheckIn;
use App\Models\MealPlanningState;
use App\Models\PlannedFood;
use App\Models\User;
use App\Support\OccurredAt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Meal/MealCheckInTests.swift and the check-in cases of
// MealMenuTests.swift (toggleCheck, saveManualCheckIn). Wednesday 23 September 2026: Breakfast 7:30 is 700 kcal,
// 30 g protein, 20 g fat and 100 g carbs; Dinner 19:00 is 1,120 kcal, 90 g protein, 25 g fat and 130 g carbs.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(function () {
    Carbon::setTestNow();
    request()->attributes->remove(OccurredAt::ATTRIBUTE);
});

function checkInMealUser(): User
{
    $user = User::factory()->create();
    SheetSeed::day($user);

    return $user;
}

/** A food whose 100 g carries the given macros, planned at 100 g in the meal. */
function checkInMealFood(string $mealId, float $kcal, float $protein, float $fat, float $carbs, int $position = 0): PlannedFood
{
    $food = Food::factory()->createOne(['kcal' => $kcal, 'protein' => $protein, 'fat' => $fat, 'carbs' => $carbs]);

    return PlannedFood::factory()->create(['meal_id' => $mealId, 'food_id' => $food->getKey(), 'grams' => 100, 'position' => $position]);
}

function checkInMeal(User $user, string $mealId, ?string $time = null, ?array $amounts = null): MealCheckIn
{
    app(CheckInMeal::class)($user, new MealCheckInInput($mealId, $time, ...($amounts ?? [])));

    return MealCheckIn::query()->where('meal_id', $mealId)->sole();
}

it('MEAL-040 checks a meal in at what its planned foods add up to, not marked manual', function () {
    $user = checkInMealUser();
    checkInMealFood(SheetSeed::BREAKFAST, 300, 12, 8, 40);
    checkInMealFood(SheetSeed::BREAKFAST, 120, 24, 1, 2, position: 1);

    $checkIn = checkInMeal($user, SheetSeed::BREAKFAST, '07:45');

    expect($checkIn->only(['user_id', 'time', 'kcal', 'protein_g', 'fat_g', 'carbs_g', 'is_manual']))->toBe([
        'user_id' => $user->id, 'time' => '07:45', 'kcal' => 420.0, 'protein_g' => 36.0, 'fat_g' => 9.0, 'carbs_g' => 42.0, 'is_manual' => false,
    ]);
});

it('MEAL-042 checks an empty meal in as given: its targets count as eaten', function () {
    $user = checkInMealUser();

    $checkIn = checkInMeal($user, SheetSeed::DINNER, '19:10');

    expect($checkIn->only(['kcal', 'protein_g', 'fat_g', 'carbs_g', 'is_manual']))->toBe([
        'kcal' => 1120.0, 'protein_g' => 90.0, 'fat_g' => 25.0, 'carbs_g' => 130.0, 'is_manual' => false,
    ]);
});

it('MEAL-041 records a manual check-in at the amounts the user entered, marked manual', function () {
    $user = checkInMealUser();
    checkInMealFood(SheetSeed::BREAKFAST, 300, 12, 8, 40);

    $checkIn = checkInMeal($user, SheetSeed::BREAKFAST, '07:45', ['kcal' => 650, 'protein_g' => 40, 'fat_g' => 18, 'carbs_g' => 80]);

    expect($checkIn->only(['time', 'kcal', 'protein_g', 'fat_g', 'carbs_g', 'is_manual']))->toBe([
        'time' => '07:45', 'kcal' => 650.0, 'protein_g' => 40.0, 'fat_g' => 18.0, 'carbs_g' => 80.0, 'is_manual' => true,
    ]);
});

it('MEAL-041 replaces the check-in already there, as one meal has one check-in', function () {
    $user = checkInMealUser();
    $first = SheetSeed::checkIn(SheetSeed::BREAKFAST, ['time' => '07:30', 'kcal' => 500, 'is_manual' => false]);

    $checkIn = checkInMeal($user, SheetSeed::BREAKFAST, '08:15', ['kcal' => 650, 'protein_g' => 40, 'fat_g' => 18, 'carbs_g' => 80]);

    expect(MealCheckIn::query()->count())->toBe(1)
        ->and($checkIn->getKey())->toBe($first->getKey())
        ->and($checkIn->only(['time', 'kcal', 'is_manual']))->toBe(['time' => '08:15', 'kcal' => 650.0, 'is_manual' => true]);
});

it('OFF-030 with no time, takes the time the user acted, not the server clock', function () {
    $user = checkInMealUser();
    request()->attributes->set(OccurredAt::ATTRIBUTE, CarbonImmutable::parse('2026-09-23T10:05:00Z'));

    expect(checkInMeal($user, SheetSeed::DINNER)->time)->toBe('10:05');
});

it('OFF-030 writes the time the user acted in the app\'s timezone', function () {
    config(['app.timezone' => 'Australia/Brisbane']);
    $user = checkInMealUser();
    request()->attributes->set(OccurredAt::ATTRIBUTE, CarbonImmutable::parse('2026-09-23T10:05:00Z'));

    expect(checkInMeal($user, SheetSeed::DINNER)->time)->toBe('20:05');
});

it('MEAL-045 refuses a one-tap check-in of a meal with the "Adjust foods" badge, and saves nothing', function () {
    $user = checkInMealUser();
    checkInMealFood(SheetSeed::BREAKFAST, 300, 12, 8, 40);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'state' => 'still_planning']);

    expect(fn () => checkInMeal($user, SheetSeed::BREAKFAST))->toThrow(DomainException::class, TodayPayload::ADJUST_FIRST)
        ->and(MealCheckIn::query()->count())->toBe(0);
});

it('MEAL-045 still takes a manual check-in of a badged meal', function () {
    $user = checkInMealUser();
    checkInMealFood(SheetSeed::BREAKFAST, 300, 12, 8, 40);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'state' => 'still_planning']);

    $checkIn = checkInMeal($user, SheetSeed::BREAKFAST, '07:45', ['kcal' => 300, 'protein_g' => 12, 'fat_g' => 8, 'carbs_g' => 40]);

    expect($checkIn->is_manual)->toBeTrue();
});

it('MEAL-045 checks in a meal done planning with one tap, though its foods miss the targets', function () {
    $user = checkInMealUser();
    checkInMealFood(SheetSeed::BREAKFAST, 300, 12, 8, 40);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'state' => 'done_planning']);

    expect(checkInMeal($user, SheetSeed::BREAKFAST, '07:45')->kcal)->toBe(300.0);
});

it('MEAL-045 checks in a meal still planning whose foods meet the targets', function () {
    $user = checkInMealUser();
    checkInMealFood(SheetSeed::BREAKFAST, 700, 30, 20, 100);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'state' => 'still_planning']);

    expect(checkInMeal($user, SheetSeed::BREAKFAST, '07:45')->kcal)->toBe(700.0);
});

it('MEAL-043 checks in a meal whose foods are within 5 g of each target as on track', function () {
    $user = checkInMealUser();
    checkInMealFood(SheetSeed::BREAKFAST, 690, 34, 16, 104);

    $checkIn = checkInMeal($user, SheetSeed::BREAKFAST, '07:45');

    $eaten = new MealTarget(kcal: $checkIn->kcal, proteinG: $checkIn->protein_g, fatG: $checkIn->fat_g, carbsG: $checkIn->carbs_g);
    expect(MealCheckInState::of($eaten, new MealTarget(kcal: 700, proteinG: 30, fatG: 20, carbsG: 100), new MealFit))->toBe(MealCheckInState::OnTrack);
});

it('MEAL-040 never checks in another user\'s meal', function () {
    checkInMealUser();

    expect(fn () => checkInMeal(User::factory()->create(), SheetSeed::BREAKFAST, '07:45'))->toThrow(ModelNotFoundException::class)
        ->and(MealCheckIn::query()->count())->toBe(0);
});
