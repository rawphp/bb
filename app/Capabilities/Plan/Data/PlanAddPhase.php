<?php

namespace App\Capabilities\Plan\Data;

use App\Domain\Model\PhaseType;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The Add a phase form (PLAN-006): where a new phase would start and, per
 * type (PH-001, PH-002), its lengths, rates and training defaults. `refusal`
 * is set when no phase can be added (the last phase has no end); the form
 * shows it and offers nothing. Recommended types follow the last phase
 * (PH-033, PH-034).
 */
final class PlanAddPhase extends CapabilityData
{
    /**
     * @param  list<PlanAddPhaseType>  $types
     */
    public function __construct(
        public readonly string $starts_text,
        public readonly ?string $refusal,
        #[Field(enum: [PhaseType::FatLoss->value, PhaseType::MuscleGain->value, PhaseType::Maintenance->value, PhaseType::MiniCut->value])]
        public readonly string $default_type,
        #[Field(items: PlanAddPhaseType::class)]
        public readonly array $types,
    ) {}
}
