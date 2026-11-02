<?php

namespace App\Capabilities\Support;

use App\Models\User;

/**
 * A read capability that names the read keys the app should keep warm for a
 * user (OFF-004). `sync_manifest` returns the keys of every capability class
 * that implements this, so which keys are listed is the server's decision.
 */
interface PrefetchesKeys
{
    /**
     * @return list<string> read keys built with {@see ReadKey}
     */
    public function prefetchKeys(User $user): array;
}
