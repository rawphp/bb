<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Spread the day's remaining calories and macros over the chosen meals (DAY-002, DAY-003). */
final class MealRedistributeInput extends CapabilityData
{
    /**
     * @param  list<string>  $meal_ids
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        public readonly array $meal_ids,
    ) {}

    public static function rules(): array
    {
        return [
            'date' => ['date_format:Y-m-d'],
        ];
    }
}
