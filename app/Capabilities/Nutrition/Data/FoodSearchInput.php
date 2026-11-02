<?php

namespace App\Capabilities\Nutrition\Data;

use App\Domain\MealFit\CuratedGroup;
use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** food_search: the Pantry's lists and search (FOOD-020 to FOOD-028). */
final class FoodSearchInput extends CapabilityData
{
    public function __construct(
        #[Field(maxLength: 100)]
        public readonly string $query = '',
        #[Field(enum: ['curated', 'recents', 'favorites', 'custom'])]
        public readonly string $tab = 'curated',
        #[Field(description: 'One of: recipes, saved_meals; or null')]
        public readonly ?string $collection = null,
        #[Field(description: 'One of: recents, favorites, curated, custom, restaurants, packaged; or null')]
        public readonly ?string $source = null,
        #[Field(description: 'One of: protein_recommended, protein_sparingly, protein_before_bed, veggies, carbs_recommended, carbs_sparingly, fat_recommended, fat_sparingly; or null')]
        public readonly ?string $group = null,
        #[Field(enum: ['g', 'oz'])]
        public readonly string $unit = 'g',
        #[Field(description: 'The meal being added to, so its foods are marked')]
        public readonly ?string $meal_id = null,
        /** @var list<string> */
        #[Field(description: 'Curated groups opened with Show 25 more')]
        public readonly array $expanded_groups = [],
    ) {}

    public static function rules(): array
    {
        return [
            'collection' => ['nullable', Rule::in(['recipes', 'saved_meals'])],
            'source' => ['nullable', Rule::in(['recents', 'favorites', 'curated', 'custom', 'restaurants', 'packaged'])],
            'group' => ['nullable', Rule::enum(CuratedGroup::class)],
            'expanded_groups.*' => [Rule::enum(CuratedGroup::class)],
        ];
    }
}
