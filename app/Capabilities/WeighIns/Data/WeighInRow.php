<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A weigh-in, or a missed scheduled day (WT-004). A missed row has empty
 * labels and fields and shows the screen's `missed` pill.
 */
final class WeighInRow extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The weigh-in\'s ULID, or "missed-<date>" for a missed day.')]
        public readonly string $id,
        public readonly bool $is_missed,
        public readonly string $date_label,
        public readonly string $time_label,
        #[Field(description: '"82.5 kg".')]
        public readonly string $weight_label,
        #[Field(description: '"86.0 cm"; empty without a waist reading (WT-021).')]
        public readonly string $waist_label,
        #[Field(description: 'When the reading was taken; for a missed day, when a late one starts.', format: 'date-time')]
        public readonly string $weighed_at,
        #[Field(description: 'The weight as the edit field starts, in the user\'s unit: "82.5".')]
        public readonly string $weight_field,
        public readonly string $waist_field,
    ) {}
}
