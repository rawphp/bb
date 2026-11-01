<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

final class WeighInScheduleEntryInput extends CapabilityData
{
    /** @param  list<int>  $weekdays */
    public function __construct(
        #[Field(description: 'ISO weekdays, 1 is Monday.')]
        public readonly array $weekdays,
        #[Field(description: 'Reminder time, "HH:MM".')]
        public readonly string $time,
    ) {}

    public static function rules(): array
    {
        return [
            'weekdays' => ['min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'time' => ['date_format:H:i'],
        ];
    }
}
