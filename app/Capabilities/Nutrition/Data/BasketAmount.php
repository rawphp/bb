<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** FOOD-030: one basket pick at the amount Add would give it. */
final class BasketAmount extends CapabilityData
{
    public function __construct(
        #[Field(description: 'The entry id the basket sent')]
        public readonly string $id,
        #[Field(description: '"210 g (about 1.4 fillets)" or "1.5 servings"; null when the meal already holds this food or recipe, so Add skips it')]
        public readonly ?string $amount_text,
    ) {}
}
