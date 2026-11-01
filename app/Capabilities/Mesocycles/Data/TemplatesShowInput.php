<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Input for templates_show (MESO-010 to MESO-012, MESO-015). Filters are omitted for
 * "All", except `phase`: omitted opens on the phase in force (MESO-011), `all` clears it. With
 * `template_id` the output's `detail` is that template (TemplateDetailView).
 */
final class TemplatesShowInput extends CapabilityData
{
    public function __construct(
        public readonly ?string $template_id = null,
        #[Field(enum: ['2', '3', '4', '5', '6'])]
        public readonly ?string $days = null,
        #[Field(enum: ['female', 'male'])]
        public readonly ?string $sex = null,
        #[Field(enum: ['whole_body', 'emphasis'])]
        public readonly ?string $focus = null,
        #[Field(enum: ['cut', 'gain', 'maintain', 'all'])]
        public readonly ?string $phase = null,
    ) {}
}
