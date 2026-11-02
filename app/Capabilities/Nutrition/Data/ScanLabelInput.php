<?php

namespace App\Capabilities\Nutrition\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** scan_label: a photo of a nutrition label (FOOD-042). */
final class ScanLabelInput extends CapabilityData
{
    public function __construct(
        #[Field(maxLength: 2_000_000, description: 'A JPEG as a base64 data URL, downscaled on the device')]
        public readonly string $image,
        #[Field(minLength: 1, maxLength: 14, description: 'The barcode that found nothing, carried into the draft (FOOD-041): 1 to 14 digits')]
        public readonly ?string $barcode = null,
    ) {}

    public static function rules(): array
    {
        return [
            'image' => [Rule::string()->startsWith('data:image/jpeg;base64,')],
            'barcode' => ['nullable', Rule::regex('/^\d{1,14}$/')],
        ];
    }
}
