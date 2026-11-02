<?php

use App\Capabilities\HostAuthorizer;
use App\Capabilities\Support\HostBus;
use App\Capabilities\Support\NoAgentPolicy;
use App\Capabilities\Support\NoBilling;
use App\Capabilities\Support\Ownership;
use App\Capabilities\Support\OwnsInput;
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

    $result = (new HostBus($registry, new Ownership))->invoke('tally_add', [], ['actor' => tap(new User, fn (User $u) => $u->id = 1)]);

    expect($result->error['message'])->toBe('Some details need fixing.')
        ->and($result->error['violations'])->toBe([['field' => 'amount', 'message' => 'This field is required.']]);
});

it('CAP-012 shows the registry\'s catalog through the host bus', function () {
    $registry = app(CapabilityRegistry::class);

    expect((new HostBus($registry, new Ownership))->catalog()->listEnvelope())->toBe($registry->catalog()->listEnvelope());
});

it('CAP-014 keeps idempotency keys in the database', function () {
    expect(app(IdempotencyStore::class))->toBeInstanceOf(DatabaseIdempotencyStore::class);
});

it('CAP-020 answers another user\'s row ID with 404, not 403', function () {
    $ownership = new Ownership;
    $ownership->declare('token_revoke', new class implements OwnsInput
    {
        public function owned(User $user): array
        {
            $query = Mockery::mock(Builder::class);
            $query->shouldReceive('whereKey->exists')->andReturn(false);

            return ['id' => $query];
        }
    });
    $registry = new CapabilityRegistry;
    app(TokenRevokeCapability::class)->definition()->register($registry);
    $registry->withAuthorizerResolver(fn () => new HostAuthorizer($registry, $ownership, new NoBilling, new NoAgentPolicy));
    $user = tap(new User, fn (User $u) => $u->id = 1);

    $foreign = (new HostBus($registry, $ownership))->invoke('token_revoke', ['id' => 'theirs'], ['actor' => $user]);

    expect($foreign->errorCode())->toBe('not_found')
        ->and($foreign->error['message'])->toBe('We couldn\'t find that.')
        ->and($ownership->pullDenied())->toBeFalse();
});

it('CAP-020 leaves a refusal for another reason a 403', function () {
    $ownership = new Ownership;
    $result = (new HostBus(app(CapabilityRegistry::class), $ownership))->invoke('account_show', [], ['actor' => SystemActor::named('scheduler'), 'caller' => 'job', 'job' => ['tenant_id' => 't']]);

    expect($result->errorCode())->toBe('forbidden');
});
