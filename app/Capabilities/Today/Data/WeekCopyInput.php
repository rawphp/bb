<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Copy a plan week's planned foods to the next week (SCH-035). */
final class WeekCopyInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $week_start,
    ) {}

    public static function rules(): array
    {
        return [
            'week_start' => ['date_format:Y-m-d'],
        ];
    }
}
