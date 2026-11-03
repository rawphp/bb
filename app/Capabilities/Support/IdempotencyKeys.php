<?php

namespace App\Capabilities\Support;

use DateTimeImmutable;
use Rawphp\Capabilities\Contracts\IdempotencyStore;
use Rawphp\Capabilities\Pipeline\ResolveActor;

/**
 * The package's idempotency store, keyed as its pipeline keys it (CAP-014): the tenant,
 * the actor's type and id, the capability and the key. AcceptOccurredAt and HostBus look
 * keys up here, so that identity is written once.
 */
final class IdempotencyKeys
{
    /**
     * The tenant the package's DefaultScopeResolver gives every invoke in this
     * single-tenant app. The feature tests that replay a write after its window and
     * after a rate limit fail if this drifts.
     */
    private const TENANT = 'default-tenant';

    public function __construct(private readonly IdempotencyStore $store) {}

    /** Whether the store holds a live answer for the actor's write under this key. */
    public function answered(object $actor, string $capability, string $key): bool
    {
        return $this->stored($actor, $capability, $key) !== null;
    }

    /**
     * The rate limit refuses a write after the pipeline has claimed its key and before it
     * runs, and the store keeps that refusal for the whole TTL, so the key could never
     * run. Nothing ran, so the claim goes, and the same key runs once the limit allows
     * (OFF-011). A key that holds anything else is left as it is.
     */
    public function releaseRateLimited(object $actor, string $capability, string $key): void
    {
        $row = $this->stored($actor, $capability, $key);
        $result = $row['result_json'] ?? null;
        $error = (is_string($result) ? json_decode($result, true) : $result)['error'] ?? null;

        if (($row['status'] ?? null) !== 'failed' || ! is_array($error) || ($error['code'] ?? null) !== 'rate_limited' || ($error['retryable'] ?? null) !== true) {
            return;
        }

        // Expiry is judged on the store's own wall clock, not the walked one.
        $this->store->update(self::TENANT, ResolveActor::actorType($actor), ResolveActor::actorId($actor), $capability, $key, [
            'expires_at' => (new DateTimeImmutable('-1 second'))->format(DATE_ATOM),
        ]);
    }

    /**
     * A run that refused a field ({@see FieldRefused}) wrote nothing, but the store keeps its answer as a plain
     * `domain_error`, without the field. The stored refusal goes, so the same key runs again and answers with the
     * field. A key that holds anything else is left as it is.
     */
    public function releaseRefused(object $actor, string $capability, string $key): void
    {
        $row = $this->stored($actor, $capability, $key);
        $result = $row['result_json'] ?? null;
        $error = (is_string($result) ? json_decode($result, true) : $result)['error'] ?? null;

        if (($row['status'] ?? null) !== 'failed' || ! is_array($error) || ($error['code'] ?? null) !== 'domain_error') {
            return;
        }

        $this->store->update(self::TENANT, ResolveActor::actorType($actor), ResolveActor::actorId($actor), $capability, $key, [
            'expires_at' => (new DateTimeImmutable('-1 second'))->format(DATE_ATOM),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function stored(object $actor, string $capability, string $key): ?array
    {
        return $this->store->find(self::TENANT, ResolveActor::actorType($actor), ResolveActor::actorId($actor), $capability, $key);
    }
}
