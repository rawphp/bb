<?php

namespace App\Capabilities\WeighIns\Data;

use Closure;
use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Replace the whole weigh-in schedule; deleting an entry is sending the rest
 * (WT-007). A weekday sits in one entry only, and the entries cover at least
 * 2 weekdays (WT-001).
 */
final class WeighInScheduleSetInput extends CapabilityData
{
    /** @param  list<WeighInScheduleEntryInput>  $entries */
    public function __construct(
        #[Field(items: WeighInScheduleEntryInput::class, minItems: 1)]
        public readonly array $entries,
    ) {}

    /** The invoke runs only this input's rules(), so the entries' rules live here. */
    public static function rules(): array
    {
        return [
            'entries' => [function (string $attribute, mixed $entries, Closure $fail): void {
                $weekdays = array_merge(...array_map(fn (mixed $entry) => (array) ($entry['weekdays'] ?? []), (array) $entries));
                if (count(array_unique($weekdays)) < 2) {
                    $fail('Weigh in on at least 2 days a week.');
                }
            }],
            // distinct on the nested wildcard compares across every entry.
            'entries.*.weekdays.*' => ['distinct'],
            'entries.*.time' => [Rule::date()->format('H:i')],
        ];
    }
}
