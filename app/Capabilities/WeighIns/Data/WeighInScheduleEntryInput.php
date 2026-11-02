<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

final class WeighInScheduleEntryInput extends CapabilityData
{
    /** @param  list<int>  $weekdays */
    public function __construct(
        #[Field(description: 'ISO weekdays, 1 is Monday.', minItems: 1, maxItems: 7)]
        public readonly array $weekdays,
        #[Field(description: 'Reminder time, "HH:MM".')]
        public readonly string $time,
    ) {}

    /** Field cannot type a list of scalars, so the weekday item type is added here. */
    public static function jsonSchema(): array
    {
        $schema = parent::jsonSchema();
        $schema['properties']['weekdays']['items'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 7];

        return $schema;
    }
}
