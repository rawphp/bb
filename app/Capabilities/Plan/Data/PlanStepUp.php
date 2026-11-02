<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * PH-044: calories step back up after Fat loss ("a 12-week diet is a 16-week
 * process"). Each step size the user may pick comes with its schedule; the
 * user can instead go straight to maintenance.
 */
final class PlanStepUp extends CapabilityData
{
    /**
     * @param  list<PlanStepChoice>  $steps
     */
    public function __construct(
        public readonly string $explanation,
        #[Field(items: PlanStepChoice::class)]
        public readonly array $steps,
    ) {}
}
