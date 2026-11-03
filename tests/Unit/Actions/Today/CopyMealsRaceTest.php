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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
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

/** Checks the meal in through the second connection, committed at once, as CheckInMeal's updateOrCreate is. */
function racedCopyCheckIn(Connection $racer, User $user, string $mealId): void
{
    $racer->table('meal_check_ins')->insert([
        'id' => strtolower((string) Str::ulid()), 'meal_id' => $mealId, 'user_id' => $user->id, 'time' => '19:35',
        'kcal' => 900, 'protein_g' => 60, 'fat_g' => 25, 'carbs_g' => 110, 'is_manual' => false,
        'created_at' => '2026-09-23 06:00:00', 'updated_at' => '2026-09-23 06:00:00',
    ]);
}

/**
 * Copies the source meal into the destination inside an outer transaction that reads first, as UndoLog's does. The
 * first `$event` on the copy's connection that `$when` accepts runs `$race`, once. Removes what racedCopyMeals() made.
 *
 * @param  class-string  $event
 * @return array{written: list<string>, rows: array{foods: list<array<string, mixed>>, recipes: list<array<string, mixed>>}, checkedIn: bool}
 */
function racedCopy(Connection $racer, User $user, string $source, string $destination, string $event, Closure $when, Closure $race): array
{
    $armed = false;
    // Left registered and disarmed after: Event::forget would also drop the framework's own listeners.
    Event::listen($event, function (object $fired) use (&$armed, $when, $race) {
        if ($armed && $fired->connectionName !== 'racer' && $when($fired)) {
            $armed = false;
            $race();
        }
    });

    try {
        $written = DB::transaction(function () use (&$armed, $user, $source, $destination) {
            DB::table('users')->count();
            $armed = true;

            return app(CopyMeals::class)($user, [[$source, $destination]], new CopyOptions);
        });

        return [
            'written' => array_map(fn (DayTargetMeal $meal) => $meal->id, $written),
            'rows' => racedCopyRows($destination),
            'checkedIn' => DB::table('meal_check_ins')->where('meal_id', $destination)->exists(),
        ];
    } finally {
        $armed = false;
        $racer->table('planned_foods')->where('user_id', $user->id)->delete();
        $racer->table('planned_recipes')->where('user_id', $user->id)->delete();
        $racer->table('users')->where('id', $user->id)->delete();
        DB::purge('racer');
    }
}

it('MEAL-060 a destination meal checked in after the first read and before the writes is not overwritten', function () {
    [$racer, $user, $source, $destination] = racedCopyMeals();
    $before = racedCopyRows($destination);

    // The check-in commits as the copy's own transaction opens: after UndoLog's first read, before the copy reads.
    $copied = racedCopy($racer, $user, $source, $destination, TransactionBeginning::class, fn () => true,
        fn () => racedCopyCheckIn($racer, $user, $destination));

    expect($copied['written'])->toBe([])
        ->and($copied['rows'])->toBe($before)
        ->and($before['foods'])->toHaveCount(1)
        ->and($before['recipes'])->toHaveCount(1)
        ->and($copied['checkedIn'])->toBeTrue();
});

it('MEAL-060 a check-in made while the copy holds the meals waits for the copy, which writes its plan', function () {
    [$racer, $user, $source, $destination] = racedCopyMeals();
    $sourceFoods = array_column(racedCopyRows($source)['foods'], 'food_id');
    $waited = null;

    // The check-in's foreign-key check needs the meal row the copy has locked, so it waits; a second is enough.
    $copied = racedCopy($racer, $user, $source, $destination, QueryExecuted::class,
        fn (QueryExecuted $query) => str_contains($query->sql, 'from `day_target_meals`') && str_ends_with($query->sql, 'for update'),
        function () use ($racer, $user, $destination, &$waited) {
            $racer->statement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                racedCopyCheckIn($racer, $user, $destination);
            } catch (QueryException $timedOut) {
                $waited = $timedOut->errorInfo[1] ?? null;
            } finally {
                $racer->statement('SET SESSION innodb_lock_wait_timeout = 50');
            }
        });

    expect($waited)->toBe(1205)
        ->and($copied['written'])->toBe([$destination])
        ->and(array_column($copied['rows']['foods'], 'food_id'))->toBe($sourceFoods)
        ->and($copied['rows']['recipes'])->toBe([])
        ->and($copied['checkedIn'])->toBeFalse();
});

it('MEAL-060 a food added to the destination after the first read is replaced by the copy, not left beside it', function () {
    [$racer, $user, $source, $destination] = racedCopyMeals();
    $sourceFoods = array_column(racedCopyRows($source)['foods'], 'food_id');
    $added = racedCopyRows($destination)['foods'][0]['food_id'];

    $copied = racedCopy($racer, $user, $source, $destination, TransactionBeginning::class, fn () => true,
        fn () => $racer->table('planned_foods')->insert([
            'id' => strtolower((string) Str::ulid()), 'user_id' => $user->id, 'meal_id' => $destination, 'food_id' => $added,
            'position' => 2, 'grams' => 40, 'is_cooked' => false, 'is_locked' => false, 'unit' => 'g',
            'created_at' => '2026-09-23 06:00:00', 'updated_at' => '2026-09-23 06:00:00',
        ]));

    expect($copied['written'])->toBe([$destination])
        ->and(array_column($copied['rows']['foods'], 'food_id'))->toBe($sourceFoods)
        ->and($copied['rows']['recipes'])->toBe([]);
});
