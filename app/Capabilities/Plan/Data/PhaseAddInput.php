<?php

namespace App\Capabilities\Plan\Data;

use App\Domain\Model\PhaseType;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Add a phase to the end of the plan (PLAN-006, PH-001 to PH-005). `phase_id`
 * is a client-made ULID (CAP-019). `weeks` null is no end (Maintenance only,
 * PH-005). `rate_percent_per_week` is signed percent of bodyweight per week
 * (TBL-010); null in training-only mode (PLAN-007).
 */
final class PhaseAddInput extends CapabilityData
{
    public function __construct(
        public readonly string $phase_id,
        #[Field(enum: [PhaseType::FatLoss->value, PhaseType::MuscleGain->value, PhaseType::Maintenance->value, PhaseType::MiniCut->value])]
        public readonly string $type,
        #[Field(minimum: 1)]
        public readonly ?int $weeks,
        public readonly ?float $rate_percent_per_week,
    ) {}

    public static function rules(): array
    {
        return ['phase_id' => ['ulid']];
    }
}
