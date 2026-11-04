<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** MEAL-046: the empty state, with the earlier meals it offers to have again (MEAL-061). */
final class MealEmpty extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $note,
        /** @var list<MealRepeat> */
        #[Field(items: MealRepeat::class, description: 'MEAL-061: newest first; none once the meal is checked in')]
        public readonly array $repeats,
    ) {}
}
