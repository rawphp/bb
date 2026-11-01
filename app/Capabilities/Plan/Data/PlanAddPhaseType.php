<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Train\Data\Measure;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One phase type in the Add a phase form. `lengths` are the lengths TBL-010
 * allows (PH-003 to PH-005, PLAN-025), each with the mesocycles that would
 * fill it (PLAN-020, PLAN-026) and its warnings (PH-034, PH-038, TBL-010) at
 * the selected rate. `rates` are the TBL-010 presets; `custom_rate_min` and
 * `custom_rate_max` bound the Custom rate input and are null where TBL-010
 * has no Custom. Both are empty or null in training-only mode (PLAN-007).
 * `training_lines` are the defaults the phase sets (PH-008, PH-039).
 */
final class PlanAddPhaseType extends CapabilityData
{
    /**
     * @param  list<PlanLengthChoice>  $lengths
     * @param  list<PlanRateChoice>  $rates
     * @param  list<PlanTextLine>  $training_lines
     */
    public function __construct(
        #[Field(enum: ['fat_loss', 'muscle_gain', 'maintenance', 'mini_cut'])]
        public readonly string $type,
        public readonly string $title,
        public readonly bool $is_recommended,
        #[Field(items: PlanLengthChoice::class)]
        public readonly array $lengths,
        #[Field(items: PlanRateChoice::class)]
        public readonly array $rates,
        public readonly ?Measure $custom_rate_min,
        public readonly ?Measure $custom_rate_max,
        #[Field(items: PlanTextLine::class)]
        public readonly array $training_lines,
    ) {}
}
