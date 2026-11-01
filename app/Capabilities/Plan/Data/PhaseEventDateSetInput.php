<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Set a phase's event date (PH-043), or clear it with null. The server re-runs
 * the PH-042 check and the PLAN-028 offer.
 */
final class PhaseEventDateSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $phase_id,
        #[Field(format: 'date')]
        public readonly ?string $event_date,
    ) {}

    public static function rules(): array
    {
        return ['event_date' => ['nullable', 'date_format:Y-m-d']];
    }
}
