<?php

use App\Capabilities\Support\HostBus;
use App\Models\User;
use Rawphp\Capabilities\Contracts\CapabilityBus;
use Rawphp\Capabilities\Contracts\IdempotencyStore;
use Rawphp\Capabilities\Persistence\DatabaseIdempotencyStore;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\Fixtures\Rail\TallyAddCapability;

it('CAP-012 sends every invoke through the host bus', function () {
    expect(app(CapabilityBus::class))->toBeInstanceOf(HostBus::class);
});

it('CAP-012 words failures from the bus in plain words', function () {
    $registry = new CapabilityRegistry;
    (new TallyAddCapability)->definition()->register($registry);

    $result = (new HostBus($registry))->invoke('tally_add', [], ['actor' => tap(new User, fn (User $u) => $u->id = 1)]);

    expect($result->error['message'])->toBe('Some details need fixing.')
        ->and($result->error['violations'])->toBe([['field' => 'amount', 'message' => 'This field is required.']]);
});

it('CAP-012 shows the registry\'s catalog through the host bus', function () {
    $registry = app(CapabilityRegistry::class);

    expect((new HostBus($registry))->catalog()->listEnvelope())->toBe($registry->catalog()->listEnvelope());
});

it('CAP-014 keeps idempotency keys in the database', function () {
    expect(app(IdempotencyStore::class))->toBeInstanceOf(DatabaseIdempotencyStore::class);
});
