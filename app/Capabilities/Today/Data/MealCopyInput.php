<?php

namespace App\Capabilities\Today\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** Copy a meal's foods to other meals (MEAL-060). */
final class MealCopyInput extends CapabilityData
{
    /**
     * @param  list<string>  $target_meal_ids
     */
    public function __construct(
        public readonly string $meal_id,
        public readonly array $target_meal_ids,
        public readonly bool $update_target_macros = true,
        public readonly bool $update_meal_times = false,
        public readonly bool $copy_locked_state = false,
    ) {}
}
