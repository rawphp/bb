<?php

namespace App\Capabilities\Reads\Data\Settings;

use App\Domain\Safety\ScreeningItem;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A screening item (SAFE-001) and whether it applies now. */
final class ScreeningQuestion extends CapabilityData
{
    public function __construct(
        #[Field(enum: [ScreeningItem::AgeOutOfRange->value, ScreeningItem::HeightOutOfRange->value, ScreeningItem::WeightOverLimit->value, ScreeningItem::BmiUnderLimit->value, ScreeningItem::PregnantOrBreastfeeding->value, ScreeningItem::TryingToConceive->value, ScreeningItem::TransgenderOnHormoneTherapy->value, ScreeningItem::HeartThyroidOrPCOS->value, ScreeningItem::WeightLossSurgery->value, ScreeningItem::GallbladderDisease->value, ScreeningItem::AutoimmuneDisease->value, ScreeningItem::Diabetes->value, ScreeningItem::EatingDisorderHistory->value])]
        public readonly string $item,
        public readonly string $label,
        public readonly bool $applies,
        #[Field(description: 'worked out from the profile, so it cannot be ticked')]
        public readonly bool $measured,
    ) {}
}
