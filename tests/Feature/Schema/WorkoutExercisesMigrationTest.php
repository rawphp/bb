<?php

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('WO-002 puts set rows that existed before workout_exercises under one exercise of their session each, in order', function () {
    $migration = require database_path('migrations/2026_11_05_000000_create_workout_exercises_table.php');
    // DDL commits on MySQL, so this test rebuilds the database when it is done.
    try {
        $migration->down();
        expect(Schema::hasTable('workout_exercises'))->toBeFalse()
            ->and(Schema::hasColumn('workout_sets', 'workout_exercise_id'))->toBeFalse();

        $user = User::factory()->create();
        $bench = Exercise::factory()->create();
        $row = Exercise::factory()->create();
        $at = ['created_at' => now(), 'updated_at' => now()];
        $session = function (int $day) use ($user, $at): string {
            $id = (string) Str::uuid();
            DB::table('workout_sessions')->insert(['id' => $id, 'user_id' => $user->id, 'week' => 1, 'day' => $day, 'is_skipped' => false] + $at);

            return $id;
        };
        $set = function (string $sessionId, ?int $exerciseId, int $position, bool $deleted = false) use ($user, $at): string {
            $id = strtolower((string) Str::ulid());
            DB::table('workout_sets')->insert([
                'id' => $id, 'user_id' => $user->id, 'workout_session_id' => $sessionId, 'exercise_id' => $exerciseId,
                'position' => $position, 'type' => 'regular', 'variant' => 'straight', 'is_logged' => true, 'is_skipped' => false,
                'deleted_at' => $deleted ? now() : null,
            ] + $at);

            return $id;
        };

        $first = $session(1);
        $second = $session(2);
        // Day 1: Row was done before Bench, so Row's block comes first. A deleted set keeps its block too.
        $rowSets = [$set($first, $row->id, 1), $set($first, $row->id, 2)];
        $benchSets = [$set($first, $bench->id, 3), $set($first, $bench->id, 4, deleted: true)];
        $gone = $set($first, null, 5);
        $other = $set($second, $bench->id, 1);

        $migration->up();

        $blockOf = fn (string $setId): ?string => DB::table('workout_sets')->where('id', $setId)->value('workout_exercise_id');
        $blocks = DB::table('workout_exercises')->orderBy('workout_session_id')->orderBy('position')->get()->keyBy('id');

        expect($blocks)->toHaveCount(4)
            ->and(array_unique(array_map($blockOf, $rowSets)))->toHaveCount(1)
            ->and(array_unique(array_map($blockOf, $benchSets)))->toHaveCount(1)
            ->and($blocks[$blockOf($rowSets[0])])->toMatchObject(['workout_session_id' => $first, 'exercise_id' => $row->id, 'position' => 1, 'user_id' => $user->id])
            ->and($blocks[$blockOf($benchSets[0])])->toMatchObject(['workout_session_id' => $first, 'exercise_id' => $bench->id, 'position' => 2])
            ->and($blocks[$blockOf($gone)])->toMatchObject(['workout_session_id' => $first, 'exercise_id' => null, 'position' => 3])
            ->and($blocks[$blockOf($other)])->toMatchObject(['workout_session_id' => $second, 'exercise_id' => $bench->id, 'position' => 1])
            ->and(collect(Schema::getColumns('workout_sets'))->firstWhere('name', 'workout_exercise_id')['nullable'])->toBeFalse()
            ->and(collect(Schema::getForeignKeys('workout_sets'))->firstWhere('columns', ['workout_exercise_id']))
            ->toMatchArray(['foreign_table' => 'workout_exercises', 'on_delete' => 'cascade']);
        foreach ($blocks->keys() as $id) {
            expect(Str::isUlid($id))->toBeTrue();
        }
    } finally {
        $this->warpRecycleDatabase();
    }
});
