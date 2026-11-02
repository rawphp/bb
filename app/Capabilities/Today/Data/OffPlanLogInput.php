<?php

namespace App\Capabilities\Today\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Log food eaten off-plan as an entry on the day (DAY-001). */
final class OffPlanLogInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'A client-made ULID (CAP-019).')] public readonly string $id,
        #[Field(format: 'date')] public readonly string $date,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $time,
        #[Field(minimum: 0)] public readonly float $kcal,
        #[Field(minimum: 0)] public readonly float $protein_g,
        #[Field(minimum: 0)] public readonly float $fat_g,
        #[Field(minimum: 0)] public readonly float $carbs_g,
        #[Field(description: 'Defaults to "Off-plan food".')] public readonly ?string $name = null,
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
            'time' => [Rule::date()->format('H:i')],
        ];
    }
}
