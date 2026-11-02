<?php

namespace App\Capabilities\Nutrition\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** scan_photo: a photo of a plate (FOOD-043, FOOD-080). */
final class ScanPhotoInput extends CapabilityData
{
    public function __construct(
        #[Field(maxLength: 2_000_000, description: 'A JPEG as a base64 data URL, downscaled on the device')]
        public readonly string $image,
    ) {}

    public static function rules(): array
    {
        return [
            'image' => [Rule::string()->startsWith('data:image/jpeg;base64,')],
        ];
    }
}
