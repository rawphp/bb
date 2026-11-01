<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Create or edit a repeating busy period (SCH-033). */
final class BusyPeriodSetInput extends CapabilityData
{
    /**
     * @param  list<int>  $weekdays
     */
    public function __construct(
        #[Field(description: 'A client-made ULID (CAP-019).')] public readonly string $id,
        public readonly string $label,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $start,
        public readonly string $end,
        #[Field(description: 'ISO weekdays it repeats on; 1 is Monday.')] public readonly array $weekdays,
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
            'start' => ['date_format:H:i'],
            'end' => ['date_format:H:i'],
            'weekdays.*' => ['integer', 'between:1,7'],
        ];
    }
}
