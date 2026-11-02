<?php

// Feature, not unit: undo is persistence itself. It snapshots real rows and
// compares them with what the database holds later, which a mock would fake.
//
// The capabilities' run() is called directly, not through the HTTP pipeline:
// the pipeline's idempotency and audit stores hold a database connection from
// before the test harness swapped it, and using it drops the test's
// RefreshDatabase transaction. UndoApplyTest covers the HTTP seam.

use App\Capabilities\Support\UndoLog;
use App\Capabilities\Support\WriteResult;
use App\Capabilities\Undo\Data\UndoApplyInput;
use App\Capabilities\Undo\UndoApplyCapability;
use App\Enums\MeasurementSource;
use App\Models\DayTarget;
use App\Models\MealCheckIn;
use App\Models\PlannedFood;
use App\Models\Step;
use App\Models\UndoToken;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Support\CapabilityContext;
use Rawphp\Capabilities\Support\CapabilityFailure;
use Tests\Support\Fixtures\Undo\StepCountSetCapability;
use Tests\Support\Fixtures\Undo\StepCountSetInput;

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 08:00:00');
    $this->user = User::factory()->create();
    $this->step = Step::factory()->for($this->user)->create(['date' => '2026-11-02', 'count' => 4000]);
    $this->weighIn = WeighIn::factory()->for($this->user)->create(['weight_kg' => 82.4]);
});

function setStepsAs(User $user, int $count): WriteResult
{
    $run = app(StepCountSetCapability::class)->definition()->toDefinition()->run;

    return $run(new StepCountSetInput($count), CapabilityContext::make(['caller' => 'http', 'actor' => $user]));
}

function undoAs(User $user, string $token): WriteResult
{
    $run = app(UndoApplyCapability::class)->definition()->toDefinition()->run;

    return $run(new UndoApplyInput($token), CapabilityContext::make(['caller' => 'http', 'actor' => $user]));
}

/** Run a write through UndoLog::record() for the user, as a reversible write's run() does. */
function recordAs(User $user, Closure $write): WriteResult
{
    return app(UndoLog::class)->record($user, function () use ($write): WriteResult {
        $write();

        return new WriteResult(invalidates: ['steps_show{}']);
    });
}

/** @return list<array<string, mixed>> every step and weigh-in row as stored */
function storedRows(): array
{
    return [
        ...Step::query()->orderBy('date')->get()->map->getRawOriginal()->all(),
        ...WeighIn::query()->get()->map->getRawOriginal()->all(),
    ];
}

it('CAP-013 marks the fake write reversible', function () {
    expect(app(StepCountSetCapability::class)->definition()->toDefinition()->tags)->toContain('reversible');
});

it('CAP-013 returns an undo token from a reversible write and stores its inverse with it', function () {
    $result = setStepsAs($this->user, 9000);

    expect($result->undo)->toBeString()->toHaveLength(26)
        ->and($result->invalidates)->toBe(['steps_show{}'])
        ->and($result->toast)->toBe('Steps set to 9000')
        ->and(Step::query()->find($this->step->id)->count)->toBe(9000)
        ->and(Step::query()->whereDate('date', '2026-11-03')->count())->toBe(1)
        ->and(WeighIn::query()->count())->toBe(0);

    $this->assertDatabaseHas('undo_tokens', ['id' => $result->undo, 'user_id' => $this->user->id]);
});

it('CAP-013 reverts a reversible write and every automatic change it made', function () {
    $before = storedRows();
    $token = setStepsAs($this->user, 9000)->undo;

    $this->travel(23)->hours();
    $undone = undoAs($this->user, $token);

    expect($undone->toArray())->toBe(['invalidates' => ['steps_show{}'], 'toast' => 'Undone.', 'undo' => null])
        ->and(storedRows())->toEqual($before);

    $this->assertDatabaseMissing('undo_tokens', ['id' => $token]);
});

it('CAP-013 refuses undo, and changes nothing, when a later write touched the same rows', function () {
    $token = setStepsAs($this->user, 9000)->undo;
    Step::query()->whereDate('date', '2026-11-03')->firstOrFail()->update(['count' => 12000]);
    $after = storedRows();

    expect(fn () => undoAs($this->user, $token))
        ->toThrow(CapabilityFailure::class, 'This can\'t be undone because it has changed since.')
        ->and(storedRows())->toEqual($after);
});

it('CAP-013 refuses undo when a later write removed a row it created', function () {
    $token = setStepsAs($this->user, 9000)->undo;
    Step::query()->whereDate('date', '2026-11-03')->firstOrFail()->delete();

    expect(fn () => undoAs($this->user, $token))
        ->toThrow(CapabilityFailure::class, 'This can\'t be undone because it has changed since.');
});

it('CAP-013 refuses undo once the token is 24 hours old', function () {
    $token = setStepsAs($this->user, 9000)->undo;

    $this->travel(24)->hours();

    expect(fn () => undoAs($this->user, $token))
        ->toThrow(CapabilityFailure::class, 'This can no longer be undone. Undo lasts 24 hours.')
        ->and(Step::query()->find($this->step->id)->count)->toBe(9000);
});

it('CAP-013 undoes a write once', function () {
    $token = setStepsAs($this->user, 9000)->undo;
    undoAs($this->user, $token);

    expect(fn () => undoAs($this->user, $token))->toThrow(ModelNotFoundException::class);
});

it('CAP-013 does not find another user\'s token', function () {
    $token = setStepsAs($this->user, 9000)->undo;

    expect(fn () => undoAs(User::factory()->create(), $token))->toThrow(ModelNotFoundException::class)
        ->and(Step::query()->find($this->step->id)->count)->toBe(9000);
});

it('CAP-013 rolls a reversible write back when it fails, and the next write still gets a token', function () {
    expect(fn () => recordAs($this->user, function () {
        $this->step->update(['count' => 9000]);
        throw new RuntimeException('The write failed.');
    }))->toThrow(RuntimeException::class, 'The write failed.');

    expect(Step::query()->find($this->step->id)->count)->toBe(4000)
        ->and(UndoToken::query()->count())->toBe(0)
        ->and(setStepsAs($this->user, 9000)->undo)->toBeString();
});

it('CAP-013 gives no undo token to a reversible write that changes nothing', function () {
    setStepsAs($this->user, 9000);

    expect(setStepsAs($this->user, 9000)->undo)->toBeNull()
        ->and(UndoToken::query()->count())->toBe(1);
});

it('CAP-013 shares one token between a reversible write and one it runs inside it, and undoes both', function () {
    $before = storedRows();

    $inner = null;
    $outer = recordAs($this->user, function () use (&$inner) {
        Step::factory()->for($this->user)->create(['date' => '2026-11-04', 'count' => 100]);
        $inner = setStepsAs($this->user, 9000);
    });

    expect($inner->undo)->toBeNull()
        ->and($outer->undo)->toBeString()
        ->and(UndoToken::query()->count())->toBe(1);

    undoAs($this->user, $outer->undo);

    expect(storedRows())->toEqual($before);
});

it('CAP-013 fails a reversible write that changes rows without a model, and changes nothing', function () {
    expect(fn () => recordAs($this->user, fn () => Step::query()->whereKey($this->step->id)->update(['count' => 9000])))
        ->toThrow(LogicException::class, 'A reversible write changed rows without an Eloquent model');

    expect(Step::query()->find($this->step->id)->count)->toBe(4000)
        ->and(UndoToken::query()->count())->toBe(0);
});

it('CAP-013 undoes a write that re-targets a day\'s meals, with the meal index and the plan of a meal it removed', function () {
    $meal = fn (string $name, int $kcal) => ['name' => $name, 'time' => '08:00', 'kcal' => $kcal, 'protein_g' => 30, 'fat_g' => 10, 'carbs_g' => 50];
    $day = DayTarget::factory()->for($this->user)->create(['meals' => [$meal('Breakfast', 500), $meal('Lunch', 700), $meal('Dinner', 800)]]);
    [$breakfast, $lunch, $dinner] = array_column($day->meals, 'id');
    PlannedFood::factory()->create(['meal_id' => $dinner]);
    MealCheckIn::factory()->create(['meal_id' => $dinner]);
    $tables = ['day_targets', 'day_target_meals', 'planned_foods', 'meal_check_ins'];
    $rows = fn () => collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->sortBy(fn ($row) => json_encode($row))->values()->all()])->all();
    $before = $rows();

    // Lunch moves first and takes Dinner's kcal, Dinner goes with its plan, and a snack is added.
    $token = recordAs($this->user, fn () => $day->update(['meals' => [
        ['id' => $lunch] + $meal('Lunch', 800),
        ['id' => $breakfast] + $meal('Breakfast', 700),
        ['id' => (string) Str::uuid7()] + $meal('Snack', 200),
    ]]))->undo;
    expect(DB::table('planned_foods')->count())->toBe(0)
        ->and(DB::table('day_target_meals')->count())->toBe(3);

    undoAs($this->user, $token);

    expect($rows())->toEqual($before);
});

it('CAP-013 refreshes the reads of a reversible write run inside another, on the write and on its undo', function () {
    $outer = app(UndoLog::class)->record($this->user, function () {
        $this->weighIn->update(['weight_kg' => 80.0]);
        setStepsAs($this->user, 9000);

        return new WriteResult(invalidates: ['weigh_ins_show{}']);
    });

    expect($outer->invalidates)->toBe(['weigh_ins_show{}', 'steps_show{}'])
        ->and(undoAs($this->user, $outer->undo)->invalidates)->toBe(['weigh_ins_show{}', 'steps_show{}']);
});

it('CAP-013 undoes a write that moves an existing row onto a row it created, putting it back before removing that row', function () {
    $sessionA = WorkoutSession::factory()->for($this->user)->create();
    $set = WorkoutSet::factory()->create(['workout_session_id' => $sessionA->id]);
    $rows = fn () => [DB::table('workout_sessions')->get()->all(), DB::table('workout_sets')->get()->all()];
    $before = $rows();

    $token = recordAs($this->user, function () use ($set) {
        $sessionB = WorkoutSession::factory()->for($this->user)->create();
        $set->update(['workout_session_id' => $sessionB->id]);
    })->undo;
    undoAs($this->user, $token);

    expect($rows())->toEqual($before);
});

it('CAP-013 fails a reversible write whose delete would cascade to rows it did not delete first', function () {
    $session = WorkoutSession::factory()->for($this->user)->create();
    WorkoutSet::factory()->create(['workout_session_id' => $session->id]);

    expect(fn () => recordAs($this->user, fn () => $session->forceDelete()))
        ->toThrow(LogicException::class, 'A reversible write deleted a workout_sessions row that other rows still point at.');

    expect(WorkoutSet::query()->count())->toBe(1)
        ->and(WorkoutSession::query()->count())->toBe(1);
});

it('CAP-013 undoes a delete of a row and the rows that pointed at it, deleted first', function () {
    $session = WorkoutSession::factory()->for($this->user)->create();
    WorkoutSet::factory()->create(['workout_session_id' => $session->id]);
    $rows = fn () => [DB::table('workout_sessions')->get()->all(), DB::table('workout_sets')->get()->all()];
    $before = $rows();

    $token = recordAs($this->user, function () use ($session) {
        $session->sets()->get()->each->forceDelete();
        $session->forceDelete();
    })->undo;
    undoAs($this->user, $token);

    expect($rows())->toEqual($before);
});

it('CAP-013 refuses undo, and keeps the later rows, when a later write added a row that points at a row it created', function () {
    $session = null;
    $token = recordAs($this->user, function () use (&$session) {
        $session = WorkoutSession::factory()->for($this->user)->create();
    })->undo;
    WorkoutSet::factory()->create(['workout_session_id' => $session->id]);

    expect(fn () => undoAs($this->user, $token))
        ->toThrow(CapabilityFailure::class, 'This can\'t be undone because something added since depends on it.');

    expect(WorkoutSession::query()->count())->toBe(1)
        ->and(WorkoutSet::query()->count())->toBe(1);
});

it('CAP-013 refuses undo of a delete when a later write added a row with the same unique key', function () {
    $token = recordAs($this->user, fn () => $this->step->delete())->undo;
    $later = Step::factory()->for($this->user)->create(['date' => '2026-11-02', 'count' => 6000]);

    expect(fn () => undoAs($this->user, $token))
        ->toThrow(CapabilityFailure::class, 'This can\'t be undone because it has changed since.');

    expect(Step::query()->pluck('id')->all())->toBe([$later->id]);
});

it('CAP-013 reads the rows it checks with a lock, so no other write lands between the check and the undo', function () {
    $token = setStepsAs($this->user, 9000)->undo;

    $reads = [];
    DB::listen(function ($query) use (&$reads) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '`steps`')) {
            $reads[] = $query->sql;
        }
    });
    undoAs($this->user, $token);

    expect($reads)->not->toBeEmpty()->each->toContain('for update');
});

it('CAP-013 reads each row with a lock before the write changes it, and all of them once after', function () {
    Step::factory()->for($this->user)->count(5)->sequence(fn ($sequence) => ['date' => '2026-10-0'.($sequence->index + 1)])->create();

    $reads = [];
    DB::listen(function ($query) use (&$reads) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '`steps`')) {
            $reads[] = $query->sql;
        }
    });
    $token = recordAs($this->user, fn () => Step::query()->whereBelongsTo($this->user)->get()->each->update(['count' => 1]))->undo;

    // The write's own load, a locking read of each of the 6 rows as it is first changed, and one read back after.
    expect($token)->toBeString()
        ->and($reads)->toHaveCount(8)
        ->and(array_slice($reads, 1, 6))->each->toContain('for update');
});

it('CAP-013 puts back only the columns the write changed, from the row as stored, not a stale model', function () {
    $stale = Step::query()->findOrFail($this->step->id);
    Step::query()->findOrFail($this->step->id)->update(['source' => MeasurementSource::Shortcut]);

    $token = recordAs($this->user, fn () => $stale->update(['count' => 9000]))->undo;
    undoAs($this->user, $token);

    $step = Step::query()->findOrFail($this->step->id);
    expect($step->count)->toBe(4000)
        ->and($step->source)->toBe(MeasurementSource::Shortcut);
});

it('CAP-013 undoes a soft delete, which keeps the rows that point at it', function () {
    $session = WorkoutSession::factory()->for($this->user)->create();
    WorkoutSet::factory()->create(['workout_session_id' => $session->id]);
    $rows = fn () => [DB::table('workout_sessions')->get()->all(), DB::table('workout_sets')->get()->all()];
    $before = $rows();

    $this->travel(1)->minutes();
    $token = recordAs($this->user, fn () => $session->delete())->undo;
    expect($token)->toBeString()
        ->and(WorkoutSession::query()->count())->toBe(0);

    undoAs($this->user, $token);

    expect($rows())->toEqual($before)
        ->and(WorkoutSession::query()->findOrFail($session->id)->deleted_at)->toBeNull();
});

it('CAP-013 refuses undo before restoring anything when a row added since points at a row the write created', function () {
    $session = null;
    $token = recordAs($this->user, function () use (&$session) {
        $this->step->update(['count' => 9000]);
        $session = WorkoutSession::factory()->for($this->user)->create();
    })->undo;
    WorkoutSet::factory()->create(['workout_session_id' => $session->id]);

    expect(fn () => undoAs($this->user, $token))
        ->toThrow(CapabilityFailure::class, 'This can\'t be undone because something added since depends on it.');

    expect(Step::query()->findOrFail($this->step->id)->count)->toBe(9000)
        ->and(WorkoutSet::query()->count())->toBe(1);
});

it('CAP-013 rolls back the rows it already put back when undo is refused partway through', function () {
    $other = Step::factory()->for($this->user)->create(['date' => '2026-11-05', 'count' => 700]);
    $token = recordAs($this->user, function () use ($other) {
        $this->step->delete();
        $other->delete();
    })->undo;
    // Undo puts the 2026-11-05 row back first, then clashes on 2026-11-02.
    $later = Step::factory()->for($this->user)->create(['date' => '2026-11-02', 'count' => 6000]);

    expect(fn () => undoAs($this->user, $token))
        ->toThrow(CapabilityFailure::class, 'This can\'t be undone because it has changed since.');

    expect(Step::query()->pluck('id')->all())->toBe([$later->id]);
});

it('CAP-013 checks what points at the rows it removes once per foreign key, not once per row', function () {
    $token = recordAs($this->user, fn () => WorkoutSession::factory()->for($this->user)->count(5)->create())->undo;
    $foreignKeys = collect(Schema::getTables())->sum(fn (array $table) => collect(Schema::getForeignKeys($table['name']))
        ->where('foreign_table', 'workout_sessions')->count());

    $checks = 0;
    DB::listen(function ($query) use (&$checks) {
        if (str_starts_with($query->sql, 'select exists')) {
            $checks++;
        }
    });
    undoAs($this->user, $token);

    expect($foreignKeys)->toBeGreaterThan(1)
        ->and($checks)->toBe($foreignKeys)
        ->and(WorkoutSession::query()->count())->toBe(0);
});

it('CAP-013 reads the foreign keys from the schema once per migration, not once per process', function () {
    $token = recordAs($this->user, fn () => WorkoutSession::factory()->for($this->user)->create())->undo;
    undoAs($this->user, $token);
    app()->forgetInstance(UndoLog::class);

    $schemaReads = 0;
    DB::listen(function ($query) use (&$schemaReads) {
        if (str_contains(strtolower($query->sql), 'information_schema')) {
            $schemaReads++;
        }
    });
    $token = recordAs($this->user, fn () => WorkoutSession::factory()->for($this->user)->create())->undo;
    undoAs($this->user, $token);

    expect($schemaReads)->toBe(0)
        ->and(WorkoutSession::query()->count())->toBe(0);
});

it('CAP-013 undoes a write that deletes a row and creates its replacement under the same unique key', function () {
    $before = storedRows();

    $token = recordAs($this->user, function () {
        $this->step->delete();
        Step::factory()->for($this->user)->create(['date' => '2026-11-02', 'count' => 9000]);
    })->undo;
    undoAs($this->user, $token);

    expect(storedRows())->toEqual($before);
});

it('CAP-013 undoes a write that moves a row off its unique key and creates another on it', function () {
    $before = storedRows();

    $token = recordAs($this->user, function () {
        $this->step->update(['date' => '2026-11-09']);
        Step::factory()->for($this->user)->create(['date' => '2026-11-02', 'count' => 9000]);
    })->undo;
    undoAs($this->user, $token);

    expect(storedRows())->toEqual($before);
});

it('CAP-013 undoes a write that created a row and a row that points at it', function () {
    $token = recordAs($this->user, function () {
        $session = WorkoutSession::factory()->for($this->user)->create();
        WorkoutSet::factory()->create(['workout_session_id' => $session->id]);
    })->undo;

    undoAs($this->user, $token);

    expect(WorkoutSession::withTrashed()->count())->toBe(0)
        ->and(WorkoutSet::withTrashed()->count())->toBe(0);
    $this->assertDatabaseMissing('undo_tokens', ['id' => $token]);
});

it('CAP-013 leaves the framework\'s own tables out of what a reversible write must change through models', function () {
    config(['cache.default' => 'database', 'queue.default' => 'database']);
    $session = WorkoutSession::factory()->for($this->user)->create();

    // A cold database cache (the foreign key map) and a queued job, both written outside models.
    $token = recordAs($this->user, function () use ($session) {
        Cache::put('unrelated', 'value');
        dispatch(fn () => null);
        $session->forceDelete();
    })->undo;

    expect($token)->toBeString()
        ->and(DB::table('cache')->count())->toBeGreaterThan(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('CAP-013 reads the schema version once per write, and checks a delete only against the foreign keys that point at its table', function () {
    $session = WorkoutSession::factory()->for($this->user)->create();
    WorkoutSet::factory()->count(5)->create(['workout_session_id' => $session->id]);
    $pointingAtSets = collect(Schema::getTables())->sum(fn (array $table) => collect(Schema::getForeignKeys($table['name']))
        ->where('foreign_table', 'workout_sets')->count());

    $versions = $checks = 0;
    DB::listen(function ($query) use (&$versions, &$checks) {
        $versions += (int) str_contains($query->sql, 'from `migrations`');
        $checks += (int) str_starts_with($query->sql, 'select exists');
    });
    $token = recordAs($this->user, fn () => $session->sets()->get()->each->forceDelete())->undo;

    // The check has to run before each delete: once a foreign key has cascaded, nothing is left to see.
    expect($token)->toBeString()
        ->and($versions)->toBe(1)
        ->and($checks)->toBe(5 * $pointingAtSets);
});

it('CAP-013 prunes expired undo tokens, every user\'s, and keeps the live ones', function () {
    $other = User::factory()->create();
    $expired = UndoToken::factory()->for($this->user)->create(['expires_at' => now()]);
    $otherExpired = UndoToken::factory()->for($other)->create(['expires_at' => now()->subHour()]);
    $live = UndoToken::factory()->for($this->user)->create(['expires_at' => now()->addMinute()]);
    $otherLive = UndoToken::factory()->for($other)->create();

    Artisan::call('model:prune', ['--model' => [UndoToken::class]]);

    expect(UndoToken::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$live->id, $otherLive->id])->sort()->values()->all());
    $this->assertDatabaseMissing('undo_tokens', ['id' => $expired->id])
        ->assertDatabaseMissing('undo_tokens', ['id' => $otherExpired->id]);
});

it('CAP-013 schedules pruning of expired undo tokens', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('model:prune');
});
