<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The template library (TemplateLibraryView, MESO-010, MESO-011, MESO-015) and,
 * when asked for, one template's detail (TemplateDetailView, MESO-012).
 */
final class TemplatesShowOutput extends CapabilityData
{
    /**
     * @param  list<TemplateFilter>  $filters
     * @param  list<TemplateRow>  $rows
     */
    public function __construct(
        #[Field(items: TemplateFilter::class)]
        public readonly array $filters,
        public readonly bool $has_active_filters,
        #[Field(items: TemplateRow::class)]
        public readonly array $rows,
        public readonly ?string $empty_text,
        public readonly ?TemplateDetail $detail,
    ) {}
}
