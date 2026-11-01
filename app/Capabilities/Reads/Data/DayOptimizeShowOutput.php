<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\OptimizeRow;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Optimize schedule's preview: each meal's time before and after (SCH-036). */
final class DayOptimizeShowOutput extends CapabilityData
{
    /**
     * @param  list<OptimizeRow>  $rows
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(items: OptimizeRow::class)] public readonly array $rows,
        #[Field(description: '"Your meals are already in the best places." or the refusal.')] public readonly ?string $message,
        public readonly bool $can_save,
    ) {}

    public static function rules(): array
    {
        return [
            'date' => ['date_format:Y-m-d'],
        ];
    }
}
