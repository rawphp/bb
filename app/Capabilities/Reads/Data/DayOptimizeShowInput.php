<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The day to preview Optimize schedule on (SCH-036). */
final class DayOptimizeShowInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
    ) {}

    public static function rules(): array
    {
        return [
            'date' => ['date_format:Y-m-d'],
        ];
    }
}
