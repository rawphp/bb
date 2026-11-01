<?php

namespace App\Capabilities\Today\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Add a meal at a time and share the day's macros over the meals not checked in (SCH-031). */
final class MealAddInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'A client-made ULID (CAP-019).')] public readonly string $id,
        #[Field(format: 'date')] public readonly string $date,
        #[Field(description: '24-hour "HH:MM".')] public readonly string $time,
    ) {}

    public static function rules(): array
    {
        return [
            'id' => ['ulid'],
            'time' => [Rule::date()->format('H:i')],
        ];
    }
}
