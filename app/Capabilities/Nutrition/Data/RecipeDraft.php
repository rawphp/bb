<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An imported or generated recipe for the user to confirm before it is saved (RCP-003, RCP-010). */
final class RecipeDraft extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        public readonly float $servings,
        #[Field(description: 'One of: breakfast, lunch, dinner, snack; or null')]
        public readonly ?string $meal_type,
        /** @var list<string> */
        public readonly array $steps,
        /** @var list<RecipeDraftItem> */
        #[Field(items: RecipeDraftItem::class)]
        public readonly array $items,
    ) {}
}
