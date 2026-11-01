<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The meal's check-in box (MEAL-043, MEAL-047, MEAL-045). */
final class MealCheckInState extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['not_checked_in', 'on_track', 'under', 'over'])] public readonly string $state,
        #[Field(description: '"Checked in, on track".')] public readonly string $label,
        #[Field(description: 'Why the box cannot be ticked yet (MEAL-045); null when it can.')] public readonly ?string $blocked_message,
        public readonly ManualCheckIn $manual,
    ) {}
}
