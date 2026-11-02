<?php

namespace App\Capabilities\Nutrition\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** food_barcode_lookup: a scanned or typed barcode (FOOD-041). */
final class FoodBarcodeLookupInput extends CapabilityData
{
    public function __construct(
        #[Field(minLength: 1, maxLength: 14, description: '1 to 14 digits')]
        public readonly string $barcode,
    ) {}

    public static function rules(): array
    {
        return [
            'barcode' => [Rule::regex('/^\d{1,14}$/')],
        ];
    }
}
