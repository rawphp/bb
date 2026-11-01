<?php

namespace App\Capabilities\Support;

use Rawphp\Capabilities\Idempotency\RequestHash;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A read key: the capability name plus its input as canonical JSON, with keys
 * sorted at every depth (CAP-017). The offline cache and `invalidates` use it,
 * so it must match the client's JSON.stringify of the same sorted input.
 */
final class ReadKey
{
    /**
     * @param  array<string, mixed>|CapabilityData  $input
     */
    public static function for(string $name, array|CapabilityData $input = []): string
    {
        $input = $input instanceof CapabilityData ? $input->toArray() : $input;
        $json = $input === []
            ? '{}'
            : json_encode(RequestHash::canonicalize($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $name.$json;
    }
}
