<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Copy sheet: copy a meal's foods or a whole day (MEAL-060, SCH-034). Give `meal_id` or `source_date`. */
final class CopyShowInput extends CapabilityData
{
    public function __construct(
        public readonly ?string $meal_id = null,
        #[Field(format: 'date')] public readonly ?string $source_date = null,
        #[Field(description: 'The destination day shown; defaults to the source\'s day.', format: 'date')] public readonly ?string $shown_date = null,
    ) {}

    public static function rules(): array
    {
        return [
            'source_date' => ['nullable', 'date_format:Y-m-d'],
            'shown_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
