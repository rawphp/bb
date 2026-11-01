<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Copies a mesocycle into a new draft (MESO-060, MESO-061, MESO-065): structure,
 * exercises and starting loads. `start_date` null takes the next plan week. `keep_priorities`
 * keeps the old priorities when the new phase would reset them (MESO-065).
 */
final class MesocycleCopyInput extends CapabilityData
{
    public function __construct(
        public readonly string $mesocycle_id,
        public readonly string $source_mesocycle_id,
        #[Field(format: 'date')]
        public readonly ?string $start_date = null,
        public readonly bool $keep_priorities = false,
    ) {}

    public static function rules(): array
    {
        return ['mesocycle_id' => ['required', 'ulid']];
    }
}
