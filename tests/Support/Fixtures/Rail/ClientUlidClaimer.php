<?php

namespace Tests\Support\Fixtures\Rail;

use App\Capabilities\Support\UsesClientUlid;
use App\Models\User;

final class ClientUlidClaimer
{
    use UsesClientUlid;

    public function claim(string $ulid, User $user): ?ClientUlidRow
    {
        return $this->ownedOrNew(ClientUlidRow::class, $ulid, $user);
    }
}
