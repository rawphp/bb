<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Settings\LearnSection;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** learn_show: the Learn index, grouped (LRN-001, LRN-002, LRN-005). */
final class LearnShowOutput extends CapabilityData
{
    /**
     * @param  list<LearnSection>  $sections
     */
    public function __construct(
        #[Field(items: LearnSection::class)]
        public readonly array $sections,
    ) {}
}
