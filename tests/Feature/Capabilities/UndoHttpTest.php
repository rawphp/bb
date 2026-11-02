<?php

// The undo round trip over HTTP: the reversible write's token and undo_apply's
// answers as the client gets them, through the package's write pipeline.

use App\Models\Step;
use App\Models\UndoToken;
use App\Models\User;
use App\Providers\CapabilitiesHostProvider;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\SystemActor;

// Registers a test-only capability, so each test gets its own application.
uses()->group('warp-isolated');

beforeEach(function () {
    // The package's stores keep the connection they resolved when this app booted. In a
    // warp-isolated app it is not the live one, and its reconnect through the manager would
    // disconnect the live connection and roll back this test's transaction. So it reconnects
    // on its own.
    $stores = app(CapabilityRegistry::class)->transactionConnection();
    if ($stores instanceof Connection && $stores !== DB::connection($stores->getName())) {
        $stores->setReconnector(function (Connection $stale): void {
            $fresh = app('db.factory')->make(config('database.connections.'.$stale->getName()), $stale->getName());
            $stale->setPdo($fresh->getRawPdo())->setReadPdo($fresh->getRawReadPdo());
        });
    }

    Carbon::setTestNow('2026-11-02 08:00:00');
    CapabilitiesHostProvider::registerFrom(base_path('tests/Support/Fixtures/Undo'), 'Tests\\Support\\Fixtures\\Undo', app(CapabilityRegistry::class), app());

    $this->user = User::factory()->create();
    $this->step = Step::factory()->for($this->user)->create(['date' => '2026-11-02', 'count' => 4000]);
});

function setStepsOverHttp(User $user, int $count, string $key): string
{
    return test()->actingAs($user)
        ->postJson('/capabilities/step_count_set', ['count' => $count], ['Idempotency-Key' => $key])
        ->assertOk()
        ->assertJsonPath('data.toast', "Steps set to {$count}")
        ->json('data.undo');
}

it('CAP-013 returns a reversible write\'s undo token over HTTP, and undo_apply reverts it', function () {
    $token = setStepsOverHttp($this->user, 9000, '01JBX8Z6Q7M0V2K3N4P5R6S7T8');

    expect($token)->toBeString()->toHaveLength(26);

    $this->actingAs($this->user)
        ->postJson('/capabilities/undo_apply', ['token' => $token], ['Idempotency-Key' => '01JBX8Z6Q7M0V2K3N4P5R6S7T9'])
        ->assertOk()
        ->assertJsonPath('data', ['invalidates' => ['steps_show{}'], 'toast' => 'Undone.', 'undo' => null]);

    expect(Step::query()->findOrFail($this->step->id)->count)->toBe(4000);
});

it('CAP-013 answers 409 with the message over HTTP when the change was changed since', function () {
    $token = setStepsOverHttp($this->user, 9000, '01JBX8Z6Q7M0V2K3N4P5R6S7T8');
    Step::query()->findOrFail($this->step->id)->update(['count' => 12000]);

    $this->actingAs($this->user)
        ->postJson('/capabilities/undo_apply', ['token' => $token], ['Idempotency-Key' => '01JBX8Z6Q7M0V2K3N4P5R6S7T9'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.message', 'This can\'t be undone because it has changed since.');

    expect(Step::query()->findOrFail($this->step->id)->count)->toBe(12000);
});

it('CAP-013 answers 409 with the message over HTTP once the token is 24 hours old', function () {
    $token = setStepsOverHttp($this->user, 9000, '01JBX8Z6Q7M0V2K3N4P5R6S7T8');
    $this->travel(24)->hours();

    $this->actingAs($this->user)
        ->postJson('/capabilities/undo_apply', ['token' => $token], ['Idempotency-Key' => '01JBX8Z6Q7M0V2K3N4P5R6S7T9'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.message', 'This can no longer be undone. Undo lasts 24 hours.');
});

it('CAP-013 refuses a reversible write and undo_apply to a caller that is not a user, before either runs', function (string $capability, Closure $input) {
    $token = setStepsOverHttp($this->user, 9000, '01JBX8Z6Q7M0V2K3N4P5R6S7T8');

    $result = app(CapabilityBus::class)->invoke($capability, $input($token), ['caller' => 'job', 'actor' => SystemActor::named('nightly')]);

    expect($result->errorCode())->toBe('forbidden')
        ->and($result->error['message'])->toBe('You can\'t do that.')
        ->and(Step::query()->findOrFail($this->step->id)->count)->toBe(9000)
        ->and(UndoToken::query()->count())->toBe(1);
})->with([
    'the reversible write' => ['step_count_set', fn (string $token) => ['count' => 100]],
    'undo_apply' => ['undo_apply', fn (string $token) => ['token' => $token]],
]);
