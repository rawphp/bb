<?php

use App\Capabilities\Support\IdempotencyKeys;
use App\Models\User;
use Rawphp\Capabilities\Support\FixedClock;
use Rawphp\Capabilities\Support\InMemoryIdempotencyStore;

/** A store holding user 7's tally_add answer under key K, on the store's wall clock. */
function storeHolding(string $status, ?array $error): InMemoryIdempotencyStore
{
    $store = new InMemoryIdempotencyStore(new FixedClock(new DateTimeImmutable));
    $store->put([
        'tenant_id' => 'default-tenant',
        'actor_type' => 'user',
        'actor_id' => '7',
        'capability_name' => 'tally_add',
        'idempotency_key' => 'K',
        'request_hash' => 'h',
        'status' => $status,
        'result_json' => $error === null ? ['ok' => true, 'data' => []] : ['ok' => false, 'error' => $error],
        'created_at' => (new DateTimeImmutable)->format(DATE_ATOM),
        'expires_at' => (new DateTimeImmutable('+8 days'))->format(DATE_ATOM),
    ]);

    return $store;
}

function user7(): User
{
    return tap(new User, fn (User $user) => $user->id = 7);
}

it('OFF-011 gives back a key the rate limit refused before the write ran', function () {
    $keys = new IdempotencyKeys(storeHolding('failed', ['code' => 'rate_limited', 'retryable' => true]));

    $keys->releaseRateLimited(user7(), 'tally_add', 'K');

    expect($keys->answered(user7(), 'tally_add', 'K'))->toBeFalse();
});

it('CAP-014 keeps a key whose write ran or whose refusal is final', function (string $status, ?array $error) {
    $keys = new IdempotencyKeys(storeHolding($status, $error));

    $keys->releaseRateLimited(user7(), 'tally_add', 'K');

    expect($keys->answered(user7(), 'tally_add', 'K'))->toBeTrue();
})->with([
    'the write ran' => ['completed', null],
    'a limit hit after the write ran' => ['failed', ['code' => 'rate_limited', 'retryable' => false]],
    'another refusal' => ['failed', ['code' => 'validation_failed', 'retryable' => false]],
]);
