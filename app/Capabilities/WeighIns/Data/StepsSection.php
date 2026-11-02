<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step counts typed by hand or posted by the bridge (WT-020). */
final class StepsSection extends CapabilityData
{
    /** @param  list<StepsRow>  $rows */
    public function __construct(
        public readonly string $title,
        #[Field(description: 'The day a new entry starts on: yesterday, since today\'s count is not final.', format: 'date')]
        public readonly string $default_date,
        #[Field(items: StepsRow::class)]
        public readonly array $rows,
    ) {}
}
