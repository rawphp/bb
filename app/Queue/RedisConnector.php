<?php

namespace App\Queue;

use Illuminate\Queue\Connectors\RedisConnector as BaseRedisConnector;

/** Laravel's Redis connector, building App\Queue\RedisQueue. */
final class RedisConnector extends BaseRedisConnector
{
    /** @param  array<string, mixed>  $config */
    public function connect(array $config): RedisQueue
    {
        return new RedisQueue(
            $this->redis, $config['queue'],
            $config['connection'] ?? $this->connection,
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? null,
            $config['migration_batch_size'] ?? -1
        );
    }
}
