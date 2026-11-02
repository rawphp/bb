<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A titled run of Pantry rows; a Curated group carries its group and "Show 25 more" (FOOD-021, FOOD-023). */
final class FoodSection extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $title,
        #[Field(description: 'One of: protein_recommended, protein_sparingly, protein_before_bed, veggies, carbs_recommended, carbs_sparingly, fat_recommended, fat_sparingly; or null')]
        public readonly ?string $group,
        public readonly ?string $show_more_title,
        /** @var list<FoodRow> */
        #[Field(items: FoodRow::class)]
        public readonly array $rows,
    ) {}
}
