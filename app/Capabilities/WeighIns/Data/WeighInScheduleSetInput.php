<?php

namespace App\Capabilities\WeighIns\Data;

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
        #[Field(items: WeighInScheduleEntryInput::class)]
        public readonly array $entries,
    ) {}

    public static function rules(): array
    {
        return ['entries' => ['min:1']];
    }
}
