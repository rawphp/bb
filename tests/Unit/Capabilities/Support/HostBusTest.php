<?php

use App\Capabilities\HostAuthorizer;
use App\Capabilities\Support\HostBus;
use App\Capabilities\Support\IdempotencyKeys;
use App\Capabilities\Support\NoAgentPolicy;
use App\Capabilities\Support\NoBilling;
use App\Capabilities\Support\Ownership;
use App\Capabilities\Support\OwnsInput;
use App\Capabilities\Support\ShownOnceIdempotencyStore;
use App\Capabilities\Tokens\TokenRevokeCapability;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Contracts\IdempotencyStore;
use Rawphp\Capabilities\Persistence\DatabaseIdempotencyStore;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\SystemActor;
use Tests\Support\Fixtures\Rail\TallyAddCapability;

it('CAP-012 sends every invoke through the host bus', function () {
    expect(app(CapabilityBus::class))->toBeInstanceOf(HostBus::class);
});

it('CAP-012 words failures from the bus in plain words', function () {
    $registry = new CapabilityRegistry;
    (new TallyAddCapability)->definition()->register($registry);

    $result = (new HostBus($registry, new Ownership, app(IdempotencyKeys::class)))->invoke('tally_add', [], ['actor' => tap(new User, fn (User $u) => $u->id = 1)]);

    expect($result->error['message'])->toBe('Some details need fixing.')
        ->and($result->error['violations'])->toBe([['field' => 'amount', 'message' => 'This field is required.']]);
});

it('CAP-012 shows the registry\'s catalog through the host bus', function () {
    $registry = app(CapabilityRegistry::class);

    expect((new HostBus($registry, new Ownership, app(IdempotencyKeys::class)))->catalog()->listEnvelope())->toBe($registry->catalog()->listEnvelope());
});

it('CAP-014 keeps idempotency keys in the database', function () {
    $store = app(IdempotencyStore::class);

    expect($store)->toBeInstanceOf(ShownOnceIdempotencyStore::class)
        ->and((fn () => $this->store)->call($store))->toBeInstanceOf(DatabaseIdempotencyStore::class);
});

it('CAP-020 answers another user\'s row ID with 404, not 403', function () {
    $ownership = new Ownership;
    $ownership->declare('token_revoke', new class implements OwnsInput
    {
        public function owned(User $user): array
        {
            $query = Mockery::mock(Builder::class);
            $query->shouldReceive('whereKey->count')->andReturn(0);

            return ['id' => $query];
        }
    });
    $registry = new CapabilityRegistry;
    app(TokenRevokeCapability::class)->definition()->register($registry);
    $registry->withAuthorizerResolver(fn () => new HostAuthorizer($registry, $ownership, new NoBilling, new NoAgentPolicy));
    $user = tap(new User, fn (User $u) => $u->id = 1);

    $foreign = (new HostBus($registry, $ownership, app(IdempotencyKeys::class)))->invoke('token_revoke', ['id' => 'theirs'], ['actor' => $user]);

    expect($foreign->errorCode())->toBe('not_found')
        ->and($foreign->error['message'])->toBe('We couldn\'t find that.');
});

it('CAP-020 keeps a refusal outside the bus from turning a later invoke\'s 403 into a 404', function () {
    $ownership = foreignRows();
    $registry = new CapabilityRegistry;
    app(TokenRevokeCapability::class)->definition()->register($registry);
    $registry->withAuthorizerResolver(fn () => new HostAuthorizer($registry, $ownership, new NoBilling, new NoAgentPolicy));
    $user = tap(new User, fn (User $u) => $u->id = 1);

    // The job and MCP surfaces call the registry, not the bus.
    expect($registry->invoke('token_revoke', ['id' => 'theirs'], ['actor' => $user])->errorCode())->toBe('forbidden');

    $refused = (new HostBus($registry, $ownership, app(IdempotencyKeys::class)))->invoke('token_revoke', ['id' => 'theirs'], ['actor' => SystemActor::named('scheduler'), 'caller' => 'job', 'job' => ['tenant_id' => 't']]);

    expect($refused->errorCode())->toBe('forbidden');
});

it('CAP-020 answers each nested invoke for its own checks', function () {
    $ownership = foreignRows();

    [$inner, $outerDenied] = $ownership->watch(fn () => $ownership->watch(fn () => $ownership->allows('token_revoke', ['id' => 'theirs'], new User)));
    [, $afterNested] = $ownership->watch(function () use ($ownership) {
        $ownership->allows('token_revoke', ['id' => 'theirs'], new User);

        return $ownership->watch(fn () => true);
    });

    expect($inner)->toBe([false, true])
        ->and($outerDenied)->toBeFalse()
        ->and($afterNested)->toBeTrue();
});

/** An Ownership whose token_revoke owns no row. */
function foreignRows(): Ownership
{
    $ownership = new Ownership;
    $ownership->declare('token_revoke', new class implements OwnsInput
    {
        public function owned(User $user): array
        {
            $query = Mockery::mock(Builder::class);
            $query->shouldReceive('whereKey->count')->andReturn(0);

            return ['id' => $query];
        }
    });

    return $ownership;
}

it('CAP-020 leaves a refusal for another reason a 403', function () {
    $ownership = new Ownership;
    $result = (new HostBus(app(CapabilityRegistry::class), $ownership, app(IdempotencyKeys::class)))->invoke('account_show', [], ['actor' => SystemActor::named('scheduler'), 'caller' => 'job', 'job' => ['tenant_id' => 't']]);

    expect($result->errorCode())->toBe('forbidden');
});
