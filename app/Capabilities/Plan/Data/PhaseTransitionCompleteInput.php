<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Support\AcceptsNullEnums;
use App\Domain\Model\PhaseType;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Confirm the transition screen (PH-030 to PH-046): end `phase_id` and save
 * what comes next. `next_phase_id` is a client-made ULID for the first new
 * phase (CAP-019); Gain first's later two phases get server IDs (PH-032).
 * `weeks` is one of the option's lengths, null for Gain first. `template` is
 * the PH-036 training choice. `calorie_mode` and `step_kcal` are PH-044 and
 * only sent for Maintenance after Fat loss with nutrition on; otherwise
 * they are null or left out.
 */
final class PhaseTransitionCompleteInput extends CapabilityData
{
    use AcceptsNullEnums;

    public function __construct(
        public readonly string $phase_id,
        public readonly string $next_phase_id,
        #[Field(enum: [PhaseType::FatLoss->value, PhaseType::MuscleGain->value, PhaseType::Maintenance->value, PhaseType::MiniCut->value, 'gain_first'])]
        public readonly string $choice,
        #[Field(minimum: 1)]
        public readonly ?int $weeks,
        #[Field(enum: ['standard', 'resensitization', 'balanced'])]
        public readonly string $template,
        #[Field(enum: ['step_up', 'straight'])]
        public readonly ?string $calorie_mode = null,
        public readonly ?int $step_kcal = null,
    ) {}

    public static function rules(): array
    {
        return ['next_phase_id' => ['ulid']];
    }
}
