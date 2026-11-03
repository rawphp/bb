<?php

use App\Capabilities\Nutrition\MealReads;
use App\Capabilities\Support\HostDefinition;
use App\Models\DayTargetMeal;
use App\Models\MealCheckIn;
use App\Models\MealPlanningState;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;

// The HTTP seam of meal_planning_done. The rules are unit tests (tests/Unit/Actions/Meals/SetMealPlanningStateTest.php).

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function mealPlanningDoneCall(?User $user, string $name, array $input): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function mealPlanningDoneUser(): User
{
    $user = User::factory()->create();
    SheetSeed::day($user);

    return $user;
}

it('CAP-011 CAP-013 registers meal_planning_done on every surface, reversible, affecting the meal reads', function () {
    $definition = app(CapabilityRegistry::class)->get('meal_planning_done');

    expect($definition->surfaces)->toBe(HostDefinition::SURFACES)
        ->and(HostDefinition::isReversible($definition))->toBeTrue()
        ->and(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe(MealReads::AFFECTS);
});

it('DAY-004 refuses meal_planning_done when signed out', function () {
    mealPlanningDoneCall(null, 'meal_planning_done', ['meal_id' => SheetSeed::BREAKFAST, 'done' => true])->assertUnauthorized();
});

it('CAP-020 answers 404 for another user\'s meal and changes nothing', function () {
    mealPlanningDoneUser();

    mealPlanningDoneCall(User::factory()->create(), 'meal_planning_done', ['meal_id' => SheetSeed::BREAKFAST, 'done' => true])->assertNotFound();

    expect(MealPlanningState::query()->count())->toBe(0);
});

it('DAY-004 answers 422 naming done when it is missing', function () {
    mealPlanningDoneCall(mealPlanningDoneUser(), 'meal_planning_done', ['meal_id' => SheetSeed::BREAKFAST])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'done');
});

it('DAY-004 MEAL-045 marks a badged meal done planning, which lets it be checked in with one tap', function () {
    $user = mealPlanningDoneUser();
    SheetSeed::food(SheetSeed::BREAKFAST);
    MealPlanningState::factory()->create(['meal_id' => SheetSeed::BREAKFAST, 'state' => 'still_planning']);
    mealPlanningDoneCall($user, 'meal_check_in', ['meal_id' => SheetSeed::BREAKFAST])->assertStatus(422);

    $data = mealPlanningDoneCall($user, 'meal_planning_done', ['meal_id' => SheetSeed::BREAKFAST, 'done' => true])->assertOk()->json('data');

    expect(MealPlanningState::query()->sole()->state)->toBe('done_planning')
        ->and($data['invalidates'])->toBe(MealReads::keys(DayTargetMeal::query()->findOrFail(SheetSeed::BREAKFAST)))
        ->and($data['undo'])->toBeString();
    mealPlanningDoneCall($user, 'meal_check_in', ['meal_id' => SheetSeed::BREAKFAST])->assertOk();
    expect(MealCheckIn::query()->count())->toBe(1);
});
