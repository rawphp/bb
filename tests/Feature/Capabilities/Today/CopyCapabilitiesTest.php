<?php

use App\Models\DayTarget;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Tests\Support\ScreenFixture;
use Tests\Support\TodaySeed;

// The HTTP seam of copy_show, meal_copy, day_copy and week_copy. The rules are CopyPlannerTest's, what the writes
// save CopyMealsTest's and the sheet CopyPayloadTest's. The seeded user's plan week of Wednesday 23 September runs
// from Saturday 19 to Friday 25; Wednesday's breakfast holds 80 g oats, its lunch is checked in and its dinner holds
// 2 servings of chili; Lower A starts at 17:30.

beforeEach(fn () => Carbon::setTestNow('2026-09-23 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function copyOver(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function copySeeded(): User
{
    $user = TodaySeed::user();
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, TodaySeed::mesocycle($user));

    return $user;
}

function copySeededMeal(User $user, string $date, int $index): string
{
    return DayTarget::query()->where('user_id', $user->id)->whereDate('date', $date)->firstOrFail()->meals[$index - 1]['id'];
}

it('MEAL-060 SCH-034 SCH-035 refuses the copy reads and writes when signed out', function (string $name, array $input) {
    copyOver(null, $name, $input)->assertUnauthorized();
})->with([
    'copy_show' => ['copy_show', ['source_date' => '2026-09-23']],
    'meal_copy' => ['meal_copy', ['meal_id' => '01JBX0M1N2P3Q4R5S6T7V8W9X0', 'target_meal_ids' => ['01JBX0M1N2P3Q4R5S6T7V8W9X1']]],
    'day_copy' => ['day_copy', ['source_date' => '2026-09-23', 'target_dates' => ['2026-09-24']]],
    'week_copy' => ['week_copy', ['week_start' => '2026-09-19']],
]);

it('MEAL-060 SCH-034 SCH-035 answers 404 for another user\'s meal or day', function (string $name, Closure $input) {
    $other = copySeeded();
    $user = TodaySeed::user();
    TodaySeed::targets($user, last: '2026-09-20');

    copyOver($user, $name, $input($other, $user))->assertNotFound();
})->with([
    'copy_show from another user\'s meal' => ['copy_show', fn (User $other) => ['meal_id' => copySeededMeal($other, '2026-09-23', 1)]],
    'copy_show from a day the user has none of' => ['copy_show', fn () => ['source_date' => '2026-09-23']],
    'meal_copy from another user\'s meal' => ['meal_copy', fn (User $other, User $user) => [
        'meal_id' => copySeededMeal($other, '2026-09-23', 1), 'target_meal_ids' => [],
    ]],
    'meal_copy into another user\'s meal' => ['meal_copy', fn (User $other, User $user) => [
        'meal_id' => copySeededMeal($user, '2026-09-19', 1), 'target_meal_ids' => [copySeededMeal($other, '2026-09-24', 1)],
    ]],
    'day_copy from a day the user has none of' => ['day_copy', fn () => ['source_date' => '2026-09-23', 'target_dates' => ['2026-09-20']]],
    'week_copy of a week the user has none of' => ['week_copy', fn () => ['week_start' => '2026-09-26']],
]);

it('MEAL-060 SCH-034 SCH-035 answers 422 naming the bad field', function (string $name, array $input, string $field) {
    copyOver(User::factory()->create(), $name, $input)
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with([
    'copy_show with no source' => ['copy_show', [], 'meal_id'],
    'meal_copy into a meal id that is not one' => ['meal_copy', ['meal_id' => '01JBX0M1N2P3Q4R5S6T7V8W9X0', 'target_meal_ids' => ['abc']], 'target_meal_ids.0'],
    'day_copy to a date that is not one' => ['day_copy', ['source_date' => '2026-09-23', 'target_dates' => ['20 Sep']], 'target_dates.0'],
    'week_copy from a week start that is not a date' => ['week_copy', ['week_start' => 'Saturday'], 'week_start'],
]);

it('MEAL-060 SCH-034 copy_show matches its screen fixtures, copying a meal\'s foods and a day', function () {
    $user = copySeeded();
    $breakfast = copySeededMeal($user, '2026-09-23', 1);

    $foods = copyOver($user, 'copy_show', ['meal_id' => strtoupper($breakfast)])->assertOk()->json('data');
    $day = copyOver($user, 'copy_show', ['source_date' => '2026-09-23', 'shown_date' => '2026-09-24'])->assertOk()->json('data');

    assertMatchesScreenFixture('copy_show', $foods);
    expect(ScreenFixture::differences(ScreenFixture::load('copy_show.day'), $day))->toBe([])
        ->and((new JsonSchemaValidator)->validate(ScreenFixture::outputSchema('copy_show'), $day))->toBe([])
        ->and($foods['source']['foods_text'])->toBe('Oats 80 g')
        // MEAL-060: the destination list shows the workout between the meals.
        ->and(array_column($foods['timeline'], 'kind'))->toBe(['meal', 'meal', 'workout', 'meal'])
        ->and($foods['workouts'][0]['name'])->toBe('Lower A')
        ->and(array_column($foods['meals'], 'note'))->toBe(['Copying from here', 'Checked in', null])
        ->and($day['source']['name'])->toBe('Wednesday, Sep 23')
        ->and($day['shown_date'])->toBe('2026-09-24');
});

it('MEAL-060 copy_show shows a day in another week with that week\'s strip and meals', function () {
    $user = copySeeded();

    $sheet = copyOver($user, 'copy_show', ['meal_id' => copySeededMeal($user, '2026-09-23', 1), 'shown_date' => '2026-09-30'])
        ->assertOk()
        ->json('data');

    expect(array_column($sheet['strip'], 'date'))->toBe(['2026-09-26', '2026-09-27', '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'])
        ->and(array_column($sheet['strip'], 'enabled'))->toBe(array_fill(0, 7, true))
        ->and(array_column($sheet['meals'], 'id'))->toBe(array_map(fn (int $i) => copySeededMeal($user, '2026-09-30', $i), [1, 2, 3]))
        ->and($sheet['source']['foods_text'])->toBe('Oats 80 g');
});

it('MEAL-060 CAP-010 CAP-013 meal_copy copies, names the reads it changed, and its undo puts the meals back', function () {
    $user = copySeeded();
    $dinner = copySeededMeal($user, '2026-09-23', 3);
    // The server made these meals, so their ids are UUIDs; a client-added meal brings a ULID (CAP-019).
    $thursday = copySeededMeal($user, '2026-09-24', 3);
    $friday = copySeededMeal($user, '2026-09-25', 1);
    $kept = PlannedFood::factory()->create(['meal_id' => $friday]);

    $response = copyOver($user, 'meal_copy', ['meal_id' => $dinner, 'target_meal_ids' => [$thursday, $friday]])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Copied to 2 meals');

    expect(PlannedRecipe::query()->whereIn('meal_id', [$thursday, $friday])->count())->toBe(2)
        ->and(PlannedFood::query()->whereKey($kept->id)->exists())->toBeFalse()
        ->and($response->json('data.invalidates'))->toContain(
            'today_show{"date":"2026-09-24"}', 'today_show{"date":"2026-09-25"}', 'week_show{"date":"2026-09-24"}',
            'week_show{"date":"2026-09-19"}', "meal_show{\"meal_id\":\"{$thursday}\"}", 'nutrition_show{"date":"2026-09-25"}',
            "copy_show{\"meal_id\":\"{$dinner}\",\"shown_date\":null,\"source_date\":null}",
        )
        ->and($response->json('data.invalidates'))->not->toContain('today_show{"date":"2026-09-23"}');

    copyOver($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect(PlannedRecipe::query()->whereIn('meal_id', [$thursday, $friday])->count())->toBe(0)
        ->and(PlannedFood::query()->where('meal_id', $friday)->pluck('food_id')->all())->toBe([$kept->food_id]);
});

it('MEAL-060 CAP-019 meal_copy copies into a meal the client added, whose id is a ULID', function () {
    $user = copySeeded();
    $thursday = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-24')->firstOrFail();
    $snack = (string) Str::ulid();
    $thursday->meals = [...$thursday->meals, ['id' => $snack, 'name' => 'Snack', 'time' => '21:00', 'kcal' => 200, 'protein_g' => 10, 'fat_g' => 5, 'carbs_g' => 20]];
    $thursday->save();

    copyOver($user, 'meal_copy', ['meal_id' => copySeededMeal($user, '2026-09-23', 1), 'target_meal_ids' => [$snack]])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Copied to 1 meal');

    expect(PlannedFood::query()->where('meal_id', strtolower($snack))->count())->toBe(1);
});

it('SCH-034 CAP-010 day_copy copies a day\'s meals to other days and names their reads', function () {
    $user = copySeeded();

    $response = copyOver($user, 'day_copy', ['source_date' => '2026-09-23', 'target_dates' => ['2026-09-24', '2026-09-30']])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Copied to 2 days');

    expect(PlannedFood::query()->where('meal_id', copySeededMeal($user, '2026-09-30', 1))->count())->toBe(1)
        ->and(PlannedRecipe::query()->where('meal_id', copySeededMeal($user, '2026-09-24', 3))->count())->toBe(1)
        ->and($response->json('data.undo'))->toBeString()
        ->and($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-24"}', 'today_show{"date":"2026-09-30"}', 'week_show{"date":"2026-09-26"}');
});

it('SCH-034 MEAL-060 CAP-013 day_copy with update meal times moves the times, and its undo puts them back', function () {
    $user = copySeeded();
    $thursday = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-24')->firstOrFail();
    $thursday->meals = array_map(fn (array $meal, string $time) => ['time' => $time] + $meal, $thursday->meals, ['09:00', '13:00', '20:00']);
    $thursday->save();
    $times = fn () => array_column($thursday->fresh()->meals, 'time');

    $response = copyOver($user, 'day_copy', ['source_date' => '2026-09-23', 'target_dates' => ['2026-09-24'], 'update_meal_times' => true])
        ->assertOk();

    // Wednesday's lunch has no foods, so Thursday's lunch keeps its time.
    expect($times())->toBe(['08:00', '13:00', '19:30']);

    copyOver($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect($times())->toBe(['09:00', '13:00', '20:00']);
});

it('SCH-035 CAP-010 week_copy copies the plan week to the next one and names its reads', function () {
    $user = copySeeded();

    $response = copyOver($user, 'week_copy', ['week_start' => '2026-09-19'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Copied to next week');

    expect(PlannedFood::query()->where('meal_id', copySeededMeal($user, '2026-09-30', 1))->count())->toBe(1)
        ->and($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-30"}', 'week_show{"date":"2026-10-02"}');
});

it('SCH-035 week_copy answers 422 in plain words when there is no next week to copy to', function () {
    $user = copySeeded();

    copyOver($user, 'week_copy', ['week_start' => '2026-10-03'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'There is no next week in the plan to copy to.');
});
