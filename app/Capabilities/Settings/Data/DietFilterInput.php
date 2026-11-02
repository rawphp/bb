<?php

namespace App\Capabilities\Settings\Data;

use App\Domain\Model\DietFilter;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One diet filter. */
final class DietFilterInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: [DietFilter::DairyFree->value, DietFilter::GlutenFree->value, DietFilter::GrainFree->value, DietFilter::LowFodmap->value, DietFilter::Vegetarian->value, DietFilter::Paleo->value, DietFilter::Vegan->value])]
        public readonly string $filter,
    ) {}
}
