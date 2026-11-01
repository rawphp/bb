<?php

namespace App\Capabilities\Plan\Data;

use App\Capabilities\Train\Data\Pill;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * What changing the phase to `type` would do (PH-009), shown before the user
 * confirms: from when, for how long, at what rate, the training defaults the
 * next mesocycle takes, and from which week the running mesocycle follows the
 * new type (`progression_text`). `warnings` never block (PH-010 to PH-012);
 * `refusal` does (TBL-010).
 */
final class PhaseGoalChoice extends CapabilityData
{
    /**
     * @param  list<Pill>  $warnings
     * @param  list<PlanTextLine>  $training_lines
     */
    public function __construct(
        #[Field(enum: ['fat_loss', 'muscle_gain', 'maintenance', 'mini_cut'])]
        public readonly string $type,
        public readonly string $title,
        public readonly string $from_text,
        public readonly string $length_text,
        public readonly ?string $rate_text,
        public readonly ?string $refusal,
        #[Field(items: Pill::class)]
        public readonly array $warnings,
        #[Field(items: PlanTextLine::class)]
        public readonly array $training_lines,
        public readonly ?string $progression_text,
    ) {}
}
