<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\AdjustDayOption;
use App\Capabilities\Reads\Data\Today\AdjustDayTotals;
use App\Capabilities\Reads\Data\Today\AdjustMealOption;
use App\Capabilities\Reads\Data\Today\AdjustProjectedMeal;
use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Adjust sheet with the move worked out (DAY-020, DAY-021, NUT-021, SAFE-018). */
final class DayAdjustShowOutput extends CapabilityData
{
    /**
     * @param  list<AdjustMealOption>  $meal_options
     * @param  list<AdjustDayOption>  $day_options
     * @param  list<AdjustProjectedMeal>  $projected_meals
     * @param  list<Status>  $warnings
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        #[Field(enum: ['meals', 'days'])] public readonly string $mode,
        #[Field(description: 'DAY-021: small misses are fine.')] public readonly string $note,
        #[Field(items: AdjustMealOption::class)] public readonly array $meal_options,
        #[Field(items: AdjustDayOption::class)] public readonly array $day_options,
        public readonly AdjustDayTotals $day,
        public readonly ?AdjustDayTotals $other_day,
        #[Field(items: AdjustProjectedMeal::class)] public readonly array $projected_meals,
        #[Field(description: 'Why the move is not allowed.')] public readonly ?string $refusal,
        #[Field(items: Status::class)] public readonly array $warnings,
        public readonly bool $can_save,
    ) {}
}
