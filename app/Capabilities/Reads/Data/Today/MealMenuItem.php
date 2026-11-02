<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** An entry of the meal menu (MEAL-009, SCH-010), in order. */
final class MealMenuItem extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['edit_macros_time', 'load_saved_meal', 'save_as_saved_meal', 'copy_foods', 'remove_foods', 'check_in_meal', 'check_in_manual', 'remove_check_in', 'still_planning', 'done_planning', 'delete_meal'])] public readonly string $action,
        public readonly string $label,
        public readonly bool $enabled,
    ) {}
}
