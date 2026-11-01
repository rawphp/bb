<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Log food eaten off-plan as an entry on the day (DAY-001). */
final class OffPlanLogInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'A client-made ULID (CAP-019).')] public readonly string $id,
        #[Field(format: 'date')] public readonly string $date,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $time,
        public readonly float $kcal,
        public readonly float $protein_g,
        public readonly float $fat_g,
        public readonly float $carbs_g,
        #[Field(description: 'Defaults to "Off-plan food".')] public readonly ?string $name = null,
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
            'date' => ['date_format:Y-m-d'],
            'time' => ['date_format:H:i'],
        ];
    }
}
