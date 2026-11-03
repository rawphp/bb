<?php

use App\Actions\Undo\ApplyUndo;
use App\Models\DayTarget;
use App\Models\Food;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('CAP-019 gives every planned food and recipe row a ULID key and keeps the rest of the row', function () {
    $migration = require database_path('migrations/2026_11_10_000000_give_planned_foods_and_recipes_ulid_ids.php');
    // DDL commits on MySQL, so this test rebuilds the database when it is done.
    try {
        $migration->down();
        foreach (['planned_foods', 'planned_recipes'] as $table) {
            $id = collect(Schema::getColumns($table))->firstWhere('name', 'id');
            expect($id['auto_increment'])->toBeTrue("{$table}.id is not an auto-increment id after down()");
        }

        $user = User::factory()->create();
        $meal = DayTarget::factory()->for($user)->create()->mealIndex()->firstOrFail();
        $food = Food::factory()->create();
        $recipe = Recipe::factory()->for($user)->create();
        $at = ['created_at' => '2026-11-01 08:00:00', 'updated_at' => '2026-11-01 09:00:00'];
        DB::table('planned_foods')->insert([
            ['user_id' => $user->id, 'meal_id' => $meal->id, 'food_id' => $food->id, 'position' => 0, 'grams' => 150.5, 'is_cooked' => true, 'is_locked' => false, 'unit' => 'g'] + $at,
            ['user_id' => $user->id, 'meal_id' => $meal->id, 'food_id' => $food->id, 'position' => 2, 'grams' => 40.0, 'is_cooked' => false, 'is_locked' => true, 'unit' => 'oz'] + $at,
        ]);
        DB::table('planned_recipes')->insert([
            ['user_id' => $user->id, 'meal_id' => $meal->id, 'recipe_id' => $recipe->id, 'position' => 1, 'servings' => 1.5, 'is_locked' => true, 'recipe' => json_encode(['name' => 'Chili'])] + $at,
        ]);
        $before = [
            'planned_foods' => DB::table('planned_foods')->orderBy('position')->get()->map(fn ($row) => (array) $row)->all(),
            'planned_recipes' => DB::table('planned_recipes')->orderBy('position')->get()->map(fn ($row) => (array) $row)->all(),
        ];

        $token = function (string $table) use ($user): string {
            $id = strtolower((string) Str::ulid());
            DB::table('undo_tokens')->insert([
                'id' => $id, 'user_id' => $user->id, 'invalidates' => '[]', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
                'changes' => json_encode([['connection' => null, 'table' => $table, 'key' => 'id', 'id' => 7, 'before' => null, 'after' => ['id' => 7, 'note' => 'planned_foods']]]),
            ]);

            return $id;
        };
        $foodToken = $token('planned_foods');
        $recipeToken = $token('planned_recipes');
        $otherToken = $token('steps');

        $migration->up();

        foreach ($before as $table => $rows) {
            $after = DB::table($table)->orderBy('position')->get()->map(fn ($row) => (array) $row)->all();
            expect($after)->toHaveCount(count($rows));
            foreach ($rows as $i => $row) {
                unset($row['id']);
                $key = $after[$i]['id'];
                unset($after[$i]['id']);
                expect(Str::isUlid($key))->toBeTrue("{$table} key {$key} is not a ULID")
                    ->and($key)->toBe(strtolower($key))
                    ->and($after[$i])->toEqual($row);
            }
            expect(collect($after)->count())->toBe(count($rows))
                ->and(DB::table($table)->distinct()->count('id'))->toBe(count($rows));

            $column = collect(Schema::getColumns($table))->firstWhere('name', 'id');
            expect($column['auto_increment'])->toBeFalse()
                ->and($column['nullable'])->toBeFalse()
                ->and(collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'])->toBe(['id'])
                ->and(collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['columns'] === ['meal_id', 'position']))->toBeTrue();
        }

        // Those tokens hold the old integer keys, so they are gone; any other stays.
        expect(DB::table('undo_tokens')->whereIn('id', [$foodToken, $recipeToken])->count())->toBe(0)
            ->and(DB::table('undo_tokens')->where('id', $otherToken)->exists())->toBeTrue();

        // An undo of a deleted token answers as an unknown or expired one does: not found (404).
        expect(fn () => (new ApplyUndo)($user, $foodToken))->toThrow(ModelNotFoundException::class);
    } finally {
        $this->warpRecycleDatabase();
    }
});

it('CAP-019 restores auto-increment ids on down() and drops the undo tokens that name the tables', function () {
    $migration = require database_path('migrations/2026_11_10_000000_give_planned_foods_and_recipes_ulid_ids.php');
    try {
        $user = User::factory()->create();
        $meal = DayTarget::factory()->for($user)->create()->mealIndex()->firstOrFail();
        $at = ['created_at' => now(), 'updated_at' => now()];
        $ids = [];
        foreach ([0, 1, 2] as $position) {
            $ids[] = $id = strtolower((string) Str::ulid());
            DB::table('planned_foods')->insert(['id' => $id, 'user_id' => $user->id, 'meal_id' => $meal->id, 'food_id' => Food::factory()->create()->id, 'position' => $position, 'grams' => 10.0 + $position, 'is_cooked' => false, 'is_locked' => false, 'unit' => 'g'] + $at);
        }
        $token = strtolower((string) Str::ulid());
        DB::table('undo_tokens')->insert([
            'id' => $token, 'user_id' => $user->id, 'invalidates' => '[]', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            'changes' => json_encode([['connection' => null, 'table' => 'planned_foods', 'key' => 'id', 'id' => $ids[0], 'before' => null, 'after' => []]]),
        ]);

        $migration->down();

        $rows = DB::table('planned_foods')->orderBy('position')->get();
        $id = collect(Schema::getColumns('planned_foods'))->firstWhere('name', 'id');
        expect($id['auto_increment'])->toBeTrue()
            ->and($rows->pluck('id')->map(fn ($key) => (int) $key)->unique()->count())->toBe(3)
            ->and($rows->pluck('grams')->map(fn ($grams) => (float) $grams)->all())->toBe([10.0, 11.0, 12.0])
            ->and(DB::table('undo_tokens')->where('id', $token)->exists())->toBeFalse();
    } finally {
        $this->warpRecycleDatabase();
    }
});
