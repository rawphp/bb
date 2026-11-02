<?php

namespace App\Capabilities\Settings\Data;

use App\Domain\Model\ConsumedTotals;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\WeightUnit;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** settings_update: units, appearance, consumed totals and micro plates (SET-001 to SET-003, EX-012). */
final class SettingsUpdateInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: [WeightUnit::Kg->value, WeightUnit::Lb->value])]
        public readonly string $weight_unit,
        #[Field(enum: [HeightUnit::Cm->value, HeightUnit::FeetInches->value])]
        public readonly string $height_unit,
        #[Field(enum: ['system', 'light', 'dark'])]
        public readonly string $appearance,
        #[Field(enum: [ConsumedTotals::Planned->value, ConsumedTotals::CheckedIn->value])]
        public readonly string $consumed_totals,
        public readonly bool $micro_plates,
    ) {}
}
