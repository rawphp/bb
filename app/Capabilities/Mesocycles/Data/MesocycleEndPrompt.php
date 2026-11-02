<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * "End this mesocycle?" (MESO-043): the deload warning, and in fat loss the offer of
 * a diet break next week (MESO-044).
 */
final class MesocycleEndPrompt extends CapabilityData
{
    public function __construct(
        public readonly string $warning,
        public readonly ?string $diet_break_offer,
    ) {}
}
