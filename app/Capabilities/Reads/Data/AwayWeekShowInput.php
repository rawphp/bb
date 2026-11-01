<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The plan week the Away sheet opens on (SCH-060). */
final class AwayWeekShowInput extends CapabilityData
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
