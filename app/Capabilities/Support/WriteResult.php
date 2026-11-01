<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * What every write returns (CAP-010): the read keys it changed, an optional
 * toast the server wrote, and an optional undo token (CAP-013).
 */
final class WriteResult extends CapabilityData
{
    /**
     * @param  list<string>  $invalidates  read keys built with {@see ReadKey}
     */
    public function __construct(
        public readonly array $invalidates,
        public readonly ?string $toast = null,
        public readonly ?string $undo = null,
    ) {}
}
