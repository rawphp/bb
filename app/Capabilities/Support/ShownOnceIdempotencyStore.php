<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Contracts\IdempotencyStore;

/**
 * The package's idempotency store, keeping no {@see ShownOnce} secret: a stored
 * result is what a replay returns (CAP-014), and the store must not hold a
 * working credential that the database only keeps hashed.
 *
 * The pipeline hands the store the output object itself, so the object says
 * whether it carries a secret. The store holds no container: a singleton that
 * did would outlive the one it captured.
 */
final class ShownOnceIdempotencyStore implements IdempotencyStore
{
    public function __construct(private readonly IdempotencyStore $store) {}

    public function find(?string $tenantId, string $actorType, string $actorId, string $capabilityName, string $key): ?array
    {
        return $this->store->find($tenantId, $actorType, $actorId, $capabilityName, $key);
    }

    public function put(array $record): array
    {
        return $this->store->put(self::withoutSecret($record));
    }

    public function claim(array $record): bool
    {
        return $this->store->claim(self::withoutSecret($record));
    }

    public function update(?string $tenantId, string $actorType, string $actorId, string $capabilityName, string $key, array $attributes): ?array
    {
        return $this->store->update($tenantId, $actorType, $actorId, $capabilityName, $key, self::withoutSecret($attributes));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function withoutSecret(array $record): array
    {
        $data = $record['result_json']['data'] ?? null;
        if ($data instanceof ShownOnce && is_array($record['result_json'])) {
            $record['result_json']['data'] = $data::withoutSecret($data->toArray());
        }

        return $record;
    }
}
