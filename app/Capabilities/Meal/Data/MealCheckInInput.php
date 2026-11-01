<?php

namespace App\Capabilities\Meal\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Check in a meal (MEAL-040 to MEAL-042). With no amounts it records the planned foods, or the targets of an empty meal. */
final class MealCheckInInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        #[Field(description: '24-hour "HH:MM"; defaults to now.')] public readonly ?string $time = null,
        public readonly ?float $kcal = null,
        public readonly ?float $protein_g = null,
        public readonly ?float $fat_g = null,
        public readonly ?float $carbs_g = null,
    ) {}

    public static function rules(): array
    {
        return [
            'time' => ['nullable', 'date_format:H:i'],
        ];
    }
}
