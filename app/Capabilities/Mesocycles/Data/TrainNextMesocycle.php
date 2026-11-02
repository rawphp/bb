<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Train\Data\Pill;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * "Build your next mesocycle" (MESO-060): when the next one starts, the mesocycle Copy
 * copies (the default), the phase filter the template library opens on (that of the new week 1; `all` with no phase),
 * the MESO-065 priority reset notice, and the MESO-064 active-rest recommendation.
 */
final class TrainNextMesocycle extends CapabilityData
{
    public function __construct(
        public readonly string $starts_text,
        public readonly string $start_date,
        public readonly string $copy_source_id,
        #[Field(enum: ['cut', 'gain', 'maintain', 'all'])]
        public readonly string $template_phase,
        public readonly ?string $priority_notice,
        public readonly ?Pill $active_rest,
    ) {}
}
