<?php

use App\Actions\Today\CopyMeals;
use App\Domain\Copy\CopyOptions;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\TodaySeed;

// A meal copy plans from the meals as they are when it writes. meal_copy is reversible, so it runs inside UndoLog's
// transaction, which has read before the copy, and under MySQL's REPEATABLE READ a plain read keeps that first view;
// the copy's locked reads are current reads. A true two-request race cannot be timed in a test, so the check-in
// commits through a second connection the moment the copy's own transaction opens. The rows are committed through that
// connection too, so this file runs without RefreshDatabase and removes what it made. The plan starts Saturday
// 2026-09-12; Wednesday's dinner holds oats, and Thursday's dinner holds rice and a chili serving.

afterEach(fn () => Carbon::setTestNow());

/**
 * The user, their targets and the two dinners, committed through a second connection.
 *
 * @return array{Connection, User, string, string}
 */
function racedCopyMeals(): array
{
    Carbon::setTestNow('2026-09-23 06:00:00');
    $default = DB::getDefaultConnection();
    config(['database.connections.racer' => config("database.connections.{$default}")]);
    $racer = DB::connection('racer');
    DB::setDefaultConnection('racer');
    try {
        $user = TodaySeed::user();
        TodaySeed::targets($user, last: '2026-09-30');
        $dinner = fn (string $date): string => DayTarget::query()->where('user_id', $user->id)->whereDate('date', $date)->firstOrFail()->meals[2]['id'];
        [$source, $destination] = [$dinner('2026-09-23'), $dinner('2026-09-24')];
        PlannedFood::factory()->create(['meal_id' => $source, 'food_id' => Food::factory()->createOne(['user_id' => $user->id, 'name' => 'Oats'])->getKey(), 'grams' => 80]);
        PlannedFood::factory()->create(['meal_id' => $destination, 'food_id' => Food::factory()->createOne(['user_id' => $user->id, 'name' => 'Rice'])->getKey(), 'position' => 0, 'grams' => 120]);
        PlannedRecipe::factory()->create(['meal_id' => $destination, 'recipe_id' => Recipe::factory()->createOne(['user_id' => $user->id, 'name' => 'Chili'])->getKey(), 'position' => 1, 'servings' => 0.5]);
    } finally {
        DB::setDefaultConnection($default);
    }

    return [$racer, User::query()->findOrFail($user->id), $source, $destination];
}

/** @return array{foods: list<array<string, mixed>>, recipes: list<array<string, mixed>>} a meal's rows as stored */
function racedCopyRows(string $mealId): array
{
    return [
        'foods' => PlannedFood::query()->where('meal_id', $mealId)->orderBy('id')->get(['id', 'food_id', 'grams', 'position'])->toArray(),
        'recipes' => PlannedRecipe::query()->where('meal_id', $mealId)->orderBy('id')->get(['id', 'recipe_id', 'servings', 'position'])->toArray(),
    ];
}

it('MEAL-060 a destination meal checked in after the first read and before the writes is not overwritten', function () {
    [$racer, $user, $source, $destination] = racedCopyMeals();
    $before = racedCopyRows($destination);
    $armed = false;
    // Left registered and disarmed after: Event::forget would also drop the framework's own listeners.
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $began) use (&$armed, $racer, $user, $destination) {
        if (! $armed || $began->connectionName === 'racer') {
            return;
        }
        $armed = false;
        $racer->table('meal_check_ins')->insert([
            'id' => strtolower((string) Str::ulid()), 'meal_id' => $destination, 'user_id' => $user->id, 'time' => '19:35',
            'kcal' => 900, 'protein_g' => 60, 'fat_g' => 25, 'carbs_g' => 110, 'is_manual' => false,
            'created_at' => '2026-09-23 06:00:00', 'updated_at' => '2026-09-23 06:00:00',
        ]);
    });

    try {
        $written = DB::transaction(function () use (&$armed, $user, $source, $destination) {
            DB::table('users')->count();
            $armed = true;

            return app(CopyMeals::class)($user, [[$source, $destination]], new CopyOptions);
        });
        $after = racedCopyRows($destination);
        $checkedIn = DB::table('meal_check_ins')->where('meal_id', $destination)->exists();
    } finally {
        $armed = false;
        $racer->table('planned_foods')->where('user_id', $user->id)->delete();
        $racer->table('planned_recipes')->where('user_id', $user->id)->delete();
        $racer->table('users')->where('id', $user->id)->delete();
        DB::purge('racer');
    }

    expect(array_map(fn (DayTargetMeal $meal) => $meal->id, $written))->toBe([])
        ->and($after)->toBe($before)
        ->and($before['foods'])->toHaveCount(1)
        ->and($before['recipes'])->toHaveCount(1)
        ->and($checkedIn)->toBeTrue();
});
