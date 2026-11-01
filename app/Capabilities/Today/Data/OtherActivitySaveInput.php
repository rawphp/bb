<?php

namespace App\Capabilities\Today\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Create or edit the day's Other activity (SCH-032, SCH-038, NUT-003). */
final class OtherActivitySaveInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'A client-made ULID (CAP-019).')] public readonly string $id,
        #[Field(format: 'date')] public readonly string $date,
        public readonly string $name,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $start,
        public readonly int $minutes,
        #[Field(enum: ['light', 'moderate', 'hard'])] public readonly string $intensity,
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
            'start' => [Rule::date()->format('H:i')],
        ];
    }
}
