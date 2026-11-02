<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** food_search: what the Pantry shows. */
final class FoodSearchOutput extends CapabilityData
{
    public function __construct(
        /** @var list<InMealFood> */
        #[Field(items: InMealFood::class)]
        public readonly array $already_in_meal,
        /** @var list<FoodSection> */
        #[Field(items: FoodSection::class)]
        public readonly array $sections,
        /** @var list<RecipeRow> */
        #[Field(items: RecipeRow::class)]
        public readonly array $recipes,
        /** @var list<SavedMealRow> */
        #[Field(items: SavedMealRow::class)]
        public readonly array $saved_meals,
        public readonly ?Suggestion $suggestion,
        public readonly ?CreateCustomFood $create_custom_food,
        /** @var list<DietChip> */
        #[Field(items: DietChip::class)]
        public readonly array $diets,
        public readonly ?string $empty_text,
    ) {}
}
