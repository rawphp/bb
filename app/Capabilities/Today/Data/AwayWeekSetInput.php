<?php

namespace App\Capabilities\Today\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Mark future plan weeks away, change them, or mark them not away (SCH-060, SCH-061). */
final class AwayWeekSetInput extends CapabilityData
{
    /**
     * @param  list<string>  $week_starts
     */
    public function __construct(
        #[Field(description: 'Plan week starts, "YYYY-MM-DD".')] public readonly array $week_starts,
        public readonly bool $away,
        #[Field(enum: ['pause', 'deload'])] public readonly string $training = 'pause',
        #[Field(enum: ['rest', 'maintenance'])] public readonly string $calories = 'rest',
        public readonly bool $keep_phase_end = false,
    ) {}

    public static function rules(): array
    {
        return [
            'week_starts.*' => [Rule::date()->format('Y-m-d')],
        ];
    }
}
