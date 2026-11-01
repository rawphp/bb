<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Change the training weekdays from the next plan week (SCH-039). */
final class TrainingWeekdaysChangeInput extends CapabilityData
{
    /**
     * @param  list<int>  $weekdays
     */
    public function __construct(
        #[Field(description: 'ISO weekdays; 1 is Monday.')] public readonly array $weekdays,
        #[Field(description: '"restructure" or "extend" when there are fewer days; the server asks for it with a validation error.')] public readonly ?string $fewer_days = null,
    ) {}

    public static function rules(): array
    {
        return [
            'weekdays.*' => ['integer', 'between:1,7'],
        ];
    }
}
