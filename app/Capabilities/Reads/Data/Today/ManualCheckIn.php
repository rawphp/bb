<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** What the manual check-in sheet opens with (MEAL-041): the time and the amounts a one-tap check-in would record. */
final class ManualCheckIn extends CapabilityData
{
    public function __construct(
        #[Field(description: '24-hour "HH:MM".')] public readonly string $time,
        public readonly float $kcal,
        public readonly float $protein_g,
        public readonly float $fat_g,
        public readonly float $carbs_g,
    ) {}
}
