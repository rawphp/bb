<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A template day (MESO-012).
 */
final class TemplateDay extends CapabilityData
{
    /**
     * @param  list<TemplateSlot>  $slots
     */
    public function __construct(
        public readonly string $label,
        #[Field(items: TemplateSlot::class)]
        public readonly array $slots,
    ) {}
}
