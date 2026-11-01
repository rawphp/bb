<?php

namespace Tests\Support\Fixtures\Rail;

use Rawphp\Capabilities\Support\CapabilityData;

final class ClientUlidRowInput extends CapabilityData
{
    public function __construct(public readonly string $id) {}

    public static function rules(): array
    {
        return ['id' => ['ulid']];
    }
}
