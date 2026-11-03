<?php

use App\Capabilities\Nutrition\MealReads;
use App\Capabilities\Support\HostDefinition;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\MealCheckIn;
use App\Models\PlannedFood;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;

// The HTTP seam of meal_delete. The rules are unit tests (tests/Unit/Actions/Meals/DeleteMealTest.php).

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function mealDeleteCall(?User $user, string $name, array $input): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('CAP-011 CAP-013 AGT-010 registers meal_delete on every surface, reversible and destructive, affecting the meal reads', function () {
    $definition = app(CapabilityRegistry::class)->get('meal_delete');

    expect($definition->surfaces)->toBe(HostDefinition::SURFACES)
        ->and(HostDefinition::isReversible($definition))->toBeTrue()
        ->and(HostDefinition::isDestructive($definition))->toBeTrue()
        ->and(HostDefinition::affects($definition))->toBe(MealReads::AFFECTS);
});

it('MEAL-009 refuses meal_delete when signed out', function () {
    mealDeleteCall(null, 'meal_delete', ['meal_id' => SheetSeed::LUNCH])->assertUnauthorized();
});

it('CAP-020 answers 404 for another user\'s meal and keeps it', function () {
    SheetSeed::day(User::factory()->create());

    mealDeleteCall(User::factory()->create(), 'meal_delete', ['meal_id' => SheetSeed::LUNCH])->assertNotFound();

    expect(DayTarget::query()->sole()->meals)->toHaveCount(3);
});

it('MEAL-009 answers 422 naming meal_id when it is missing', function () {
    mealDeleteCall(User::factory()->create(), 'meal_delete', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'meal_id');
});

it('MEAL-009 CAP-013 deletes a meal with its foods and check-in, names every meal of its day, and undo brings it all back', function () {
    $user = User::factory()->create();
    $day = SheetSeed::day($user);
    $food = SheetSeed::food(SheetSeed::LUNCH);
    $checkIn = SheetSeed::checkIn(SheetSeed::LUNCH);
    $meals = DayTargetMeal::query()->orderBy('position')->get();
    $keys = array_values(array_unique(array_merge(...$meals->map(fn (DayTargetMeal $meal) => MealReads::keys($meal))->all())));

    $data = mealDeleteCall($user, 'meal_delete', ['meal_id' => SheetSeed::LUNCH])->assertOk()->json('data');

    expect(array_column($day->fresh()->meals, 'id'))->toBe([SheetSeed::BREAKFAST, SheetSeed::DINNER])
        ->and(PlannedFood::query()->count() + MealCheckIn::query()->count())->toBe(0)
        ->and($data['invalidates'])->toEqualCanonicalizing($keys);

    mealDeleteCall($user, 'undo_apply', ['token' => $data['undo']])->assertOk();

    expect(array_column($day->fresh()->meals, 'id'))->toBe([SheetSeed::BREAKFAST, SheetSeed::LUNCH, SheetSeed::DINNER])
        ->and(PlannedFood::query()->sole()->getKey())->toBe($food->getKey())
        ->and(MealCheckIn::query()->sole()->getKey())->toBe($checkIn->getKey());
});
