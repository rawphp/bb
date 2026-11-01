<?php

namespace App\Capabilities\Reads\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The day Today shows. */
final class TodayShowInput extends CapabilityData
{
    public function __construct(
        #[Field(description: 'Defaults to today.', format: 'date')] public readonly ?string $date = null,
    ) {}

    public static function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
