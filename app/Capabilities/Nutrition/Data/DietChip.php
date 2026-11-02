<?php

namespace App\Capabilities\Nutrition\Data;

use App\Domain\Model\DietFilter;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A diet filter (FOOD-027) and whether it is on. */
final class DietChip extends CapabilityData
{
    public function __construct(
        #[Field(enum: [DietFilter::DairyFree->value, DietFilter::GlutenFree->value, DietFilter::GrainFree->value, DietFilter::LowFodmap->value, DietFilter::Vegetarian->value, DietFilter::Paleo->value, DietFilter::Vegan->value])]
        public readonly string $key,
        public readonly string $title,
        public readonly bool $is_on,
    ) {}
}
