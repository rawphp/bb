<?php

use App\Capabilities\Nutrition\MealReads;
use App\Capabilities\Support\HostDefinition;
use App\Models\DayTargetMeal;
use App\Models\MealCheckIn;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;

// The HTTP seam of meal_check_in_undo. The rules are unit tests (tests/Unit/Actions/Meals/RemoveMealCheckInTest.php).

beforeEach(fn () => Carbon::setTestNow('2026-09-23 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function mealCheckInUndoCall(?User $user, array $input, array $headers = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/meal_check_in_undo', $input, ['Idempotency-Key' => (string) Str::ulid(), ...$headers]);
}

function mealCheckInUndoUser(): User
{
    $user = User::factory()->create();
    SheetSeed::day($user);
    SheetSeed::checkIn(SheetSeed::BREAKFAST);

    return $user;
}

it('CAP-011 CAP-013 registers meal_check_in_undo on every surface, reversible, affecting the meal reads', function () {
    $definition = app(CapabilityRegistry::class)->get('meal_check_in_undo');

    expect($definition->surfaces)->toBe(HostDefinition::SURFACES)
        ->and(HostDefinition::isReversible($definition))->toBeTrue()
        ->and(HostDefinition::isDestructive($definition))->toBeFalse()
        ->and(HostDefinition::affects($definition))->toBe(MealReads::AFFECTS);
});

it('MEAL-044 refuses meal_check_in_undo when signed out', function () {
    mealCheckInUndoCall(null, ['meal_id' => SheetSeed::BREAKFAST])->assertUnauthorized();
});

it('CAP-020 answers 404 for another user\'s meal and keeps its check-in', function () {
    mealCheckInUndoUser();

    mealCheckInUndoCall(User::factory()->create(), ['meal_id' => SheetSeed::BREAKFAST])->assertNotFound();

    expect(MealCheckIn::query()->count())->toBe(1);
});

it('MEAL-044 answers 422 for a meal that is not checked in', function () {
    mealCheckInUndoCall(mealCheckInUndoUser(), ['meal_id' => SheetSeed::LUNCH])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'This meal is not checked in.');
});

it('MEAL-044 OFF-030 removes a check-in the outbox replays under the same idempotency key once, and names the meal reads', function () {
    $user = mealCheckInUndoUser();
    $headers = ['Idempotency-Key' => (string) Str::ulid(), 'X-BB-Occurred-At' => '2026-09-23T10:00:00Z'];

    $first = mealCheckInUndoCall($user, ['meal_id' => SheetSeed::BREAKFAST], $headers)->assertOk();
    $replay = mealCheckInUndoCall($user, ['meal_id' => SheetSeed::BREAKFAST], $headers)->assertOk()->assertJsonPath('meta.idempotent_replay', true);

    expect($replay->json('data'))->toEqual($first->json('data'))
        ->and($first->json('data.invalidates'))->toBe(MealReads::keys(DayTargetMeal::query()->findOrFail(SheetSeed::BREAKFAST)))
        ->and($first->json('data.undo'))->toBeString()
        ->and(MealCheckIn::query()->count())->toBe(0);
});

it('CAP-013 undo of a removed check-in brings it back as it was', function () {
    $user = mealCheckInUndoUser();
    $before = MealCheckIn::query()->sole();
    $token = mealCheckInUndoCall($user, ['meal_id' => SheetSeed::BREAKFAST])->assertOk()->json('data.undo');
    expect(MealCheckIn::query()->count())->toBe(0);

    test()->actingAs($user)->postJson('/capabilities/undo_apply', ['token' => $token], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    expect(MealCheckIn::query()->sole()->only(['id', 'meal_id', 'time', 'kcal', 'protein_g', 'fat_g', 'carbs_g', 'is_manual']))
        ->toBe($before->only(['id', 'meal_id', 'time', 'kcal', 'protein_g', 'fat_g', 'carbs_g', 'is_manual']));
});
