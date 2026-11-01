<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** scan_adjust: the scanned meal after an edit: a portion step, a typed amount, a new match or a removed row (FOOD-043, FOOD-045). Nothing is stored. */
final class ScanAdjustInput extends CapabilityData
{
    public function __construct(
        /** @var list<ScanAdjustRow> */
        #[Field(items: ScanAdjustRow::class)]
        public readonly array $rows,
        #[Field(description: 'The portion the rows\' grams are at')]
        public readonly float $from_portion,
        #[Field(minimum: 0.25, maximum: 4)]
        public readonly float $portion,
    ) {}
}
