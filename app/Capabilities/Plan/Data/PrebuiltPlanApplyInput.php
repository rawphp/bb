<?php

namespace App\Capabilities\Plan\Data;

use App\Domain\Model\PhaseType;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Add one of RP's 24-week plans to the end of the plan (PLAN-030). Only its
 * phases are stored. `first_phase_id` is a client-made ULID for the first
 * phase (CAP-019); the rest get server IDs.
 */
final class PrebuiltPlanApplyInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: [PhaseType::MuscleGain->value, PhaseType::FatLoss->value])]
        public readonly string $plan,
        public readonly string $first_phase_id,
    ) {}

    public static function rules(): array
    {
        return ['first_phase_id' => ['ulid']];
    }
}
