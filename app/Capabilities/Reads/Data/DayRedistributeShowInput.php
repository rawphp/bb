<?php

namespace App\Capabilities\Reads\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Redistribute picker with the meals the user left out (DAY-002). */
final class DayRedistributeShowInput extends CapabilityData
{
    /**
     * @param  list<string>  $excluded_meal_ids
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(description: 'Ids of meals left out.')] public readonly array $excluded_meal_ids = [],
    ) {}

    public static function rules(): array
    {
        return [
            // A meal the server made has a UUID; one a client added has its ULID (CAP-019).
            'excluded_meal_ids.*' => [Rule::anyOf([['ulid'], ['uuid']])],
        ];
    }
}
