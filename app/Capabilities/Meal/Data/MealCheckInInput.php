<?php

namespace App\Capabilities\Meal\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Check in a meal (MEAL-040 to MEAL-042). With no amounts it records the planned foods, or the targets of an empty meal. A manual check-in gives all four amounts. */
final class MealCheckInInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        #[Field(description: '24-hour "HH:MM"; defaults to when the user acted.')] public readonly ?string $time = null,
        #[Field(minimum: 0)] public readonly ?float $kcal = null,
        #[Field(minimum: 0)] public readonly ?float $protein_g = null,
        #[Field(minimum: 0)] public readonly ?float $fat_g = null,
        #[Field(minimum: 0)] public readonly ?float $carbs_g = null,
    ) {}

    public static function rules(): array
    {
        return [
            'time' => ['nullable', Rule::date()->format('H:i')],
            'kcal' => ['nullable', 'required_with:protein_g,fat_g,carbs_g'],
            'protein_g' => ['nullable', 'required_with:kcal,fat_g,carbs_g'],
            'fat_g' => ['nullable', 'required_with:kcal,protein_g,carbs_g'],
            'carbs_g' => ['nullable', 'required_with:kcal,protein_g,fat_g'],
        ];
    }
}
