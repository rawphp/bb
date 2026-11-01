<?php

namespace App\Capabilities\Mesocycles\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Saves the create flow (MESO-003 to MESO-007, MESO-012 to MESO-014). A new
 * `mesocycle_id` starts a draft and replaces any other draft (MESO-007); after that the same ID
 * updates it. `template_id` fills the days, priorities and name from a template (MESO-012).
 * An omitted field leaves the stored value alone; `days` replaces every day and slot (MESO-014).
 * `finish` saves it as a mesocycle, which needs a name, a day and a phase on the start (MESO-003).
 */
final class MesocycleBuildInput extends CapabilityData
{
    /**
     * @param  list<MesocycleBuildDay>|null  $days
     */
    public function __construct(
        public readonly string $mesocycle_id,
        #[Field(enum: ['template', 'builder', 'scratch'])]
        public readonly ?string $route = null,
        public readonly ?string $template_id = null,
        public readonly ?string $name = null,
        public readonly ?int $weeks = null,
        #[Field(format: 'date')]
        public readonly ?string $start_date = null,
        #[Field(enum: ['weekdays', 'floating'])]
        public readonly ?string $label_style = null,
        public readonly ?int $days_per_week = null,
        #[Field(items: MesocycleBuildDay::class)]
        public readonly ?array $days = null,
        public readonly bool $finish = false,
    ) {}

    public static function rules(): array
    {
        return [
            'mesocycle_id' => ['required', 'ulid'],
            'days.*.day_id' => ['required', 'ulid'],
            'days.*.slots.*.slot_id' => ['required', 'ulid'],
        ];
    }
}
