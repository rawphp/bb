<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Answer the missed-day prompt (SCH-057). */
final class MissedSessionResolveInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly int $week,
        public readonly int $day,
        #[Field(enum: ['do_it_today', 'next_rest_day', 'skip'])] public readonly string $choice,
        #[Field(description: 'The day it moves to, from the prompt\'s choice; required unless the choice is skip.', format: 'date')] public readonly ?string $date = null,
    ) {}

    public static function rules(): array
    {
        return [
            'date' => ['required_unless:choice,skip'],
        ];
    }
}
