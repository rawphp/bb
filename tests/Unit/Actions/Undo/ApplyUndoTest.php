<?php

use App\Actions\Undo\ApplyUndo;
use App\Models\Step;
use App\Models\UndoToken;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Rawphp\Capabilities\Support\CapabilityFailure;

// The action's job is the rows it puts back, so it runs on the database. Each token is
// built by hand, as UndoLog::record() would store it, so these tests hold apply's rules alone.
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 08:00:00');
    $this->user = User::factory()->create();
});

/** @return array<string, mixed>|null the row as stored */
function stored(string $table, int|string $id): ?array
{
    $row = DB::table($table)->find($id);

    return $row === null ? null : (array) $row;
}

/** @param list<array<string, mixed>> $changes */
function tokenFor(User $user, array $changes, ?Carbon $expires = null): UndoToken
{
    return UndoToken::factory()->for($user)->create([
        'changes' => $changes,
        'invalidates' => ['steps_show{}'],
        'expires_at' => $expires ?? now()->addHours(UndoToken::LIFETIME_HOURS),
    ]);
}

/** @return array{connection: null, table: string, key: string, id: int|string} */
function rowRef(string $table, int|string $id): array
{
    return ['connection' => null, 'table' => $table, 'key' => 'id', 'id' => $id];
}

it('CAP-013 puts an updated row back, removes a created one, restores a deleted one, and spends the token', function () {
    $updated = Step::factory()->for($this->user)->create(['date' => '2026-11-01', 'count' => 4000]);
    $before = stored('steps', $updated->id);
    $deleted = Step::factory()->for($this->user)->create(['date' => '2026-10-31', 'count' => 3000]);
    $deletedBefore = stored('steps', $deleted->id);
    $updated->update(['count' => 9000]);
    $deleted->delete();
    $created = Step::factory()->for($this->user)->create(['date' => '2026-11-02', 'count' => 9000]);

    $token = tokenFor($this->user, [
        [...rowRef('steps', $updated->id), 'before' => $before, 'after' => stored('steps', $updated->id)],
        [...rowRef('steps', $deleted->id), 'before' => $deletedBefore, 'after' => null],
        [...rowRef('steps', $created->id), 'before' => null, 'after' => stored('steps', $created->id)],
    ]);

    $result = (new ApplyUndo)($this->user, $token->id);

    expect($result->toArray())->toBe(['invalidates' => ['steps_show{}'], 'toast' => 'Undone.', 'undo' => null])
        ->and(stored('steps', $updated->id))->toEqual($before)
        ->and(stored('steps', $deleted->id))->toEqual($deletedBefore)
        ->and(stored('steps', $created->id))->toBeNull()
        ->and(UndoToken::query()->count())->toBe(0);
});

it('CAP-013 does not find a token that belongs to another user', function () {
    $token = tokenFor(User::factory()->create(), []);

    expect(fn () => (new ApplyUndo)($this->user, $token->id))->toThrow(ModelNotFoundException::class);
});

it('CAP-013 refuses a token that is 24 hours old and changes nothing', function () {
    $step = Step::factory()->for($this->user)->create(['count' => 9000]);
    $token = tokenFor($this->user, [[...rowRef('steps', $step->id), 'before' => null, 'after' => stored('steps', $step->id)]], expires: now());

    expect(fn () => (new ApplyUndo)($this->user, $token->id))
        ->toThrow(CapabilityFailure::class, 'This can no longer be undone. Undo lasts 24 hours.')
        ->and(stored('steps', $step->id))->not->toBeNull();
});

it('CAP-013 refuses a token whose row a later write changed, and changes nothing', function () {
    $step = Step::factory()->for($this->user)->create(['count' => 4000]);
    $before = stored('steps', $step->id);
    $step->update(['count' => 9000]);
    $token = tokenFor($this->user, [[...rowRef('steps', $step->id), 'before' => $before, 'after' => stored('steps', $step->id)]]);
    $step->update(['count' => 12000]);

    expect(fn () => (new ApplyUndo)($this->user, $token->id))
        ->toThrow(CapabilityFailure::class, 'This can\'t be undone because it has changed since.')
        ->and($step->fresh()->count)->toBe(12000);
});

it('CAP-013 refuses to remove a created row that a row added since points at', function () {
    $session = WorkoutSession::factory()->for($this->user)->create();
    $token = tokenFor($this->user, [[...rowRef('workout_sessions', $session->id), 'before' => null, 'after' => stored('workout_sessions', $session->id)]]);
    WorkoutSet::factory()->create(['workout_session_id' => $session->id]);

    expect(fn () => (new ApplyUndo)($this->user, $token->id))
        ->toThrow(CapabilityFailure::class, 'This can\'t be undone because something added since depends on it.')
        ->and(WorkoutSession::query()->count())->toBe(1)
        ->and(WorkoutSet::query()->count())->toBe(1);
});
