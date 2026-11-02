<?php

namespace App\Capabilities\Nutrition\Data;

use App\Domain\Model\DietFilter;
use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** recipes_show: a list and its filters (RCP-020). */
final class RecipesShowInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['mine', 'library'])]
        public readonly string $segment = 'mine',
        #[Field(description: 'One of: breakfast, lunch, dinner, snack; or null')]
        public readonly ?string $meal_type = null,
        /** @var list<string> */
        #[Field(description: 'Diet filter keys')]
        public readonly array $diets = [],
    ) {}

    public static function rules(): array
    {
        return [
            'meal_type' => ['nullable', Rule::in(['breakfast', 'lunch', 'dinner', 'snack'])],
            'diets.*' => [Rule::enum(DietFilter::class)],
        ];
    }
}
