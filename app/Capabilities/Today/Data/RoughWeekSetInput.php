<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Mark or unmark the current plan week as a rough week (SCH-063). */
final class RoughWeekSetInput extends CapabilityData
{
    public function __construct(
        #[Field(format: 'date')] public readonly string $week_start,
        public readonly bool $rough,
    ) {}

    public static function rules(): array
    {
        return [
            'week_start' => ['date_format:Y-m-d'],
        ];
    }
}
