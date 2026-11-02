<?php

namespace App\Capabilities\Mesocycles\Data;

use App\Capabilities\Support\ExplicitNulls;
use App\Domain\Model\Sex;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Input for templates_show (MESO-010 to MESO-012, MESO-015). A filter left out
 * or null is "All" (the option's null `value`), except `phase`: left out or
 * null opens on the phase in force (MESO-011), `all` clears it. With
 * `template_id` the output's `detail` is that template (TemplateDetailView).
 */
final class TemplatesShowInput extends CapabilityData
{
    use ExplicitNulls;

    public function __construct(
        public readonly ?string $template_id = null,
        #[Field(enum: ['2', '3', '4', '5', '6'])]
        public readonly ?string $days = null,
        #[Field(enum: [Sex::Female->value, Sex::Male->value])]
        public readonly ?string $sex = null,
        #[Field(enum: ['whole_body', 'emphasis'])]
        public readonly ?string $focus = null,
        #[Field(enum: ['cut', 'gain', 'maintain', 'all'])]
        public readonly ?string $phase = null,
    ) {}
}
