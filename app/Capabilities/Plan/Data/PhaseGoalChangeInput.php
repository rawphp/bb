<?php

namespace App\Capabilities\Plan\Data;

use App\Domain\Model\PhaseType;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Change a phase's type from the next plan week (PH-009), as previewed by one
 * of `phase_show`'s goals. A running phase ends there and a new one starts,
 * so `new_phase_id` is a client-made ULID for it (CAP-019); a phase that has
 * not started changes type in place and the ULID goes unused.
 */
final class PhaseGoalChangeInput extends CapabilityData
{
    public function __construct(
        public readonly string $phase_id,
        #[Field(enum: [PhaseType::FatLoss->value, PhaseType::MuscleGain->value, PhaseType::Maintenance->value, PhaseType::MiniCut->value])]
        public readonly string $type,
        public readonly string $new_phase_id,
    ) {}

    public static function rules(): array
    {
        return ['new_phase_id' => ['ulid']];
    }
}
