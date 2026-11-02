<?php

namespace App\Capabilities\Sync\Data;

use Rawphp\Capabilities\Support\CapabilityData;

final class SyncManifestOutput extends CapabilityData
{
    /**
     * @param  list<string>  $keys  read keys built with ReadKey
     */
    public function __construct(
        public readonly array $keys,
    ) {}
}
