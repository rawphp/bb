<?php

namespace App\Agent;

use App\Capabilities\Support\HostBus;

/**
 * The Coach turn the current invoke runs for, if any (AGT-013). {@see HostBus}
 * holds the turn ULID the package's TurnRunner passes as `$options['agent']['turn_ulid']`
 * for the length of each invoke, so AuditRecordWriter can link the row to the turn.
 * Bound as a singleton; a nested invoke holds its own and the outer one is put back.
 */
final class CurrentTurn
{
    private ?string $ulid = null;

    public function ulid(): ?string
    {
        return $this->ulid;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $invoke
     * @return T
     */
    public function during(?string $ulid, callable $invoke): mixed
    {
        $outer = $this->ulid;
        $this->ulid = $ulid;

        try {
            return $invoke();
        } finally {
            $this->ulid = $outer;
        }
    }
}
