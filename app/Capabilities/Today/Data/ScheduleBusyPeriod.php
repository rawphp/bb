<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A busy period of Edit schedule. */
final class ScheduleBusyPeriod extends CapabilityData
{
    public function __construct(
        public readonly string $label,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $start,
        public readonly string $end,
    ) {}

    public static function rules(): array
    {
        return [
            'start' => ['date_format:H:i'],
            'end' => ['date_format:H:i'],
        ];
    }
}
