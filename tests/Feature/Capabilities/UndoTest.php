<?php

// Feature, not unit: undo is persistence itself. It snapshots real rows and
// compares them with what the database holds later, which a mock would fake.
//
// The capabilities' run() is called directly, not through the HTTP pipeline:
// the pipeline's idempotency and audit stores hold a database connection from
// before the test harness swapped it, and using it drops the test's
// RefreshDatabase transaction. UndoApplyTest covers the HTTP seam.

use App\Capabilities\Support\WriteResult;
use App\Capabilities\Undo\Data\UndoApplyInput;
use App\Capabilities\Undo\UndoApplyCapability;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
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

it('CAP-013 removes a user\'s expired tokens when it stores a new one', function () {
    $old = setStepsAs($this->user, 9000)->undo;
    $this->travel(25)->hours();

    setStepsAs($this->user, 9500);

    $this->assertDatabaseMissing('undo_tokens', ['id' => $old]);
});
