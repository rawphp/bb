<?php

namespace App\Capabilities\Nutrition\Data;

use App\Capabilities\Support\DomainValues;
use App\Domain\Model\MealCheckInState;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** MEAL-047: the meal's check-in state, with the text and tone of its icon. */
final class MealCheckIn extends CapabilityData
{
    public function __construct(
        #[Field(enum: [MealCheckInState::NotCheckedIn->value, MealCheckInState::OnTrack->value, MealCheckInState::Under->value, MealCheckInState::Over->value])]
        public readonly string $state,
        public readonly string $text,
        #[Field(enum: DomainValues::TONES)]
        public readonly string $tone,
    ) {}
}
