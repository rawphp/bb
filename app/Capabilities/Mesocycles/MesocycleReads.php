<?php

namespace App\Capabilities\Mesocycles;

use App\Capabilities\Support\ReadKey;

/** The reads a mesocycle write changes (CAP-011, CAP-017): the Train tab and each mesocycle it changed. */
final class MesocycleReads
{
    /** @return list<string> */
    public static function keys(string ...$mesocycleIds): array
    {
        return [
            ReadKey::for('train_show'),
            ...array_map(fn (string $id) => ReadKey::for('mesocycle_show', ['mesocycle_id' => $id]), array_values(array_unique($mesocycleIds))),
        ];
    }
}
