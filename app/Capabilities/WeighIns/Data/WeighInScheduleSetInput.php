<?php

namespace App\Capabilities\WeighIns\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Replace the whole weigh-in schedule. The entries must cover at least 2
 * different weekdays (WT-001, WT-007); deleting an entry is sending the rest.
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
            'entries.*.weekdays.*' => [Rule::numeric()->integer(strict: true)->between(1, 7), 'distinct'],
            'entries.*.time' => [Rule::date()->format('H:i')],
        ];
    }
}
