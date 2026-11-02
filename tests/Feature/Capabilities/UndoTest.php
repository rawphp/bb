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
use App\Models\DayTarget;
use App\Models\Step;
use App\Models\UndoToken;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

it('CAP-013 fails a reversible write that saves a day target, whose meal index is kept without models', function () {
    expect(fn () => recordAs($this->user, fn () => DayTarget::factory()->for($this->user)->create()))
        ->toThrow(LogicException::class, 'A reversible write changed rows without an Eloquent model');

    expect(DayTarget::query()->count())->toBe(0);
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

it('CAP-013 reads the rows a write changed once per table, not once per row', function () {
    Step::factory()->for($this->user)->count(5)->sequence(fn ($sequence) => ['date' => '2026-10-0'.($sequence->index + 1)])->create();

    $reads = 0;
    DB::listen(function ($query) use (&$reads) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '`steps`')) {
            $reads++;
        }
    });
    $token = recordAs($this->user, fn () => Step::query()->whereBelongsTo($this->user)->get()->each->update(['count' => 1]))->undo;

    // One to load the steps for the write, one to read them back after it.
    expect($token)->toBeString()->and($reads)->toBe(2);
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
