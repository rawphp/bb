<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** An item of a drafted recipe and the food it matched (RCP-003, RCP-010). */
final class RecipeDraftItem extends CapabilityData
{
    public function __construct(
        public readonly string $name,
        public readonly float $grams,
        public readonly ?string $food_id,
        public readonly string $match_text,
        public readonly ?string $detail,
    ) {}
}
