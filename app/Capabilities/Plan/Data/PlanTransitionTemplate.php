<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The training the next phase's first mesocycle defaults to (PH-008, PH-036,
 * PH-039, TBL-012). `detail` describes a resensitization mesocycle; null
 * otherwise.
 */
final class PlanTransitionTemplate extends CapabilityData
{
    /**
     * @param  list<PlanTextLine>  $training_lines
     */
    public function __construct(
        #[Field(enum: ['standard', 'resensitization', 'balanced'])]
        public readonly string $template,
        public readonly string $title,
        public readonly ?string $detail,
        #[Field(items: PlanTextLine::class)]
        public readonly array $training_lines,
        public readonly bool $is_selected,
    ) {}
}
