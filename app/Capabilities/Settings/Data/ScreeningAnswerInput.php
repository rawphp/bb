<?php

namespace App\Capabilities\Settings\Data;

use App\Domain\Safety\ScreeningItem;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Whether one screening item applies. */
final class ScreeningAnswerInput extends CapabilityData
{
    public function __construct(
        #[Field(enum: [ScreeningItem::AgeOutOfRange->value, ScreeningItem::HeightOutOfRange->value, ScreeningItem::WeightOverLimit->value, ScreeningItem::BmiUnderLimit->value, ScreeningItem::PregnantOrBreastfeeding->value, ScreeningItem::TryingToConceive->value, ScreeningItem::TransgenderOnHormoneTherapy->value, ScreeningItem::HeartThyroidOrPCOS->value, ScreeningItem::WeightLossSurgery->value, ScreeningItem::GallbladderDisease->value, ScreeningItem::AutoimmuneDisease->value, ScreeningItem::Diabetes->value, ScreeningItem::EatingDisorderHistory->value])]
        public readonly string $item,
        public readonly bool $applies,
    ) {}
}
