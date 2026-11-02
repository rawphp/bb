<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Log a weigh-in, on any day (WT-002, WT-006), in the user's units tagged
 * with the unit (CAP-015). The client makes the ULID (CAP-019).
 */
final class WeighInLogInput extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        #[Field(format: 'date-time')]
        public readonly string $weighed_at,
        public readonly WeightInput $weight,
        #[Field(description: 'WT-021: optional waist measurement.')]
        public readonly ?WaistInput $waist = null,
    ) {}

    public static function rules(): array
    {
        return ['id' => ['ulid']];
    }
}
