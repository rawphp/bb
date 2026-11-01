<?php

namespace App\Queue;

use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Connectors\DatabaseConnector as BaseDatabaseConnector;

/** Laravel's database connector, building App\Queue\DatabaseQueue. */
final class DatabaseConnector extends BaseDatabaseConnector
{
    public function __construct(private readonly DatabaseManager $db)
    {
        parent::__construct($db);
    }

    /** @param  array<string, mixed>  $config */
    public function connect(array $config): DatabaseQueue
    {
        return new DatabaseQueue(
            $this->db->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null
        );
    }
}
