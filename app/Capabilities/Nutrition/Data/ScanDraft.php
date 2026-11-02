<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The scanned meal (FOOD-043 to FOOD-046): nothing is saved until the user adds it or saves it as a saved meal. scan_photo, scan_describe and scan_adjust each return it through their own Output class. */
abstract class ScanDraft extends CapabilityData
{
    public function __construct(
        /** @var list<ScanRow> */
        #[Field(items: ScanRow::class)]
        public readonly array $rows,
        public readonly float $portion,
        #[Field(description: '1.25×')]
        public readonly string $portion_text,
        #[Field(description: 'The next smaller portion, or null at the smallest')]
        public readonly ?float $portion_down,
        #[Field(description: 'The next larger portion, or null at the largest')]
        public readonly ?float $portion_up,
        public readonly Macros $totals,
        public readonly ?string $unmatched_note,
        /** @var list<SavedMealFood> */
        #[Field(items: SavedMealFood::class, description: 'The matched foods, merged per food: what Add to meal and Save as saved meal send')]
        public readonly array $matched,
        public readonly string $saved_meal_name,
        public readonly ?string $empty_text,
    ) {}
}
