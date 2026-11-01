<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Edit a weigh-in's time, weight or waist (WT-004). */
final class WeighInUpdateInput extends CapabilityData
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
