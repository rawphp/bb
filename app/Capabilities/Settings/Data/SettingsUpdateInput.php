<?php

namespace App\Capabilities\Settings\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** settings_update: units, appearance, consumed totals and micro plates (SET-001 to SET-003, EX-012). */
final class SettingsUpdateInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['kg', 'lb'])]
        public readonly string $weight_unit,
        #[Field(enum: ['cm', 'feetInches'])]
        public readonly string $height_unit,
        #[Field(enum: ['system', 'light', 'dark'])]
        public readonly string $appearance,
        #[Field(enum: ['planned', 'checked_in'])]
        public readonly string $consumed_totals,
        public readonly bool $micro_plates,
    ) {}
}
