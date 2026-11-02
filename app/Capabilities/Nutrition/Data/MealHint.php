<?php

namespace App\Capabilities\Nutrition\Data;

use App\Domain\MealFit\CuratedGroup;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** MEAL-007: a macro the chosen foods cannot reach, and the curated group the Pantry opens on. */
final class MealHint extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        #[Field(enum: [CuratedGroup::ProteinRecommended->value, CuratedGroup::ProteinSparingly->value, CuratedGroup::ProteinBeforeBed->value, CuratedGroup::Veggies->value, CuratedGroup::CarbsRecommended->value, CuratedGroup::CarbsSparingly->value, CuratedGroup::FatRecommended->value, CuratedGroup::FatSparingly->value])]
        public readonly string $group,
    ) {}
}
