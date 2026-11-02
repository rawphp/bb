<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** Delete a weigh-in (WT-004). */
final class WeighInDeleteInput extends CapabilityData
{
    public function __construct(
        public readonly string $id,
    ) {}

    public static function rules(): array
    {
        return ['id' => ['ulid']];
    }
}
