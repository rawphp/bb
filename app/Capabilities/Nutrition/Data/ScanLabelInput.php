<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** scan_label: a photo of a nutrition label (FOOD-042). */
final class ScanLabelInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'A JPEG as a base64 data URL, downscaled on the device')]
        public readonly string $image,
        #[Field(description: 'The barcode that found nothing, carried into the draft (FOOD-041)')]
        public readonly ?string $barcode = null,
    ) {}
}
