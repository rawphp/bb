<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One of RP's two 24-week plans (PLAN-030), laid out from where the plan ends.
 * `refusal` is set when it cannot be added (the last phase has no end,
 * PLAN-006). `prebuilt_plan_apply` sends `plan`.
 */
final class PlanPrebuilt extends CapabilityData
{
    /**
     * @param  list<PlanPrebuiltPhase>  $phases
     * @param  list<PlanPrebuiltSegment>  $segments
     */
    public function __construct(
        #[Field(enum: ['muscle_gain', 'fat_loss'])]
        public readonly string $plan,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $starts_text,
        public readonly ?string $refusal,
        #[Field(items: PlanPrebuiltPhase::class)]
        public readonly array $phases,
        #[Field(items: PlanPrebuiltSegment::class)]
        public readonly array $segments,
    ) {}
}
