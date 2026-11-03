<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** MEAL-009: a meal menu item, with whether it can be used now. A meal still being planned offers done_planning in place of still_planning. */
final class MenuItem extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['edit_macros_time', 'load_saved_meal', 'save_as_saved_meal', 'copy_foods', 'remove_foods', 'check_in_meal', 'check_in_manual', 'remove_check_in', 'still_planning', 'done_planning', 'delete_meal'])]
        public readonly string $item,
        public readonly string $title,
        public readonly bool $is_enabled,
    ) {}
}
