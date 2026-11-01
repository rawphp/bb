<?php

namespace App\Capabilities\Today\Data;

use Illuminate\Validation\Rule;
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
            'start' => [Rule::date()->format('H:i')],
            'end' => [Rule::date()->format('H:i')],
            'weekdays.*' => [Rule::numeric()->integer(strict: true)->between(1, 7)],
        ];
    }
}
