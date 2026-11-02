<?php

namespace App\Capabilities\Support;

use Closure;
use Rawphp\Capabilities\Contracts\IdempotencyStore;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The package's idempotency store, keeping no {@see ShownOnce} secret: a stored
 * result is what a replay returns (CAP-014), and the store must not hold a
 * working credential that the database only keeps hashed.
 */
final class ShownOnceIdempotencyStore implements IdempotencyStore
{
    /**
     * @param  Closure(): CapabilityRegistry  $registry  resolved late, as the registry's pipeline holds this store
     */
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly Closure $registry,
    ) {}

    public function find(?string $tenantId, string $actorType, string $actorId, string $capabilityName, string $key): ?array
    {
        return $this->store->find($tenantId, $actorType, $actorId, $capabilityName, $key);
    }

    public function put(array $record): array
    {
        return $this->store->put($this->withoutSecret($record, (string) ($record['capability_name'] ?? '')));
    }

    public function claim(array $record): bool
    {
        return $this->store->claim($this->withoutSecret($record, (string) ($record['capability_name'] ?? '')));
    }

    public function update(?string $tenantId, string $actorType, string $actorId, string $capabilityName, string $key, array $attributes): ?array
    {
        return $this->store->update($tenantId, $actorType, $actorId, $capabilityName, $key, $this->withoutSecret($attributes, $capabilityName));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function withoutSecret(array $record, string $capability): array
    {
        $result = $record['result_json'] ?? null;
        if (! is_array($result) || ! array_key_exists('data', $result) || ! ($this->registry)()->has($capability)) {
            return $record;
        }

        $output = ($this->registry)()->get($capability)->output;
        if ($output === null || ! is_a($output, ShownOnce::class, true)) {
            return $record;
        }

        $data = $result['data'] instanceof CapabilityData ? $result['data']->toArray() : $result['data'];
        if (is_array($data)) {
            $record['result_json']['data'] = $output::withoutSecret($data);
        }

        return $record;
    }
}
