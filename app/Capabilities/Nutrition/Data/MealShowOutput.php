<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** meal_show: the meal screen (MEAL-001 to MEAL-011, MEAL-046, MEAL-047, DAY-003, RCP-002). */
final class MealShowOutput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $title,
        public readonly ?string $time_text,
        /** @var list<MacroBar> */
        #[Field(items: MacroBar::class)]
        public readonly array $bars,
        /** @var list<Pill> */
        #[Field(items: Pill::class, description: 'MEAL-011: calories, then protein')]
        public readonly array $pills,
        #[Field(description: 'MEAL-003')]
        public readonly ?Pill $targets_met,
        #[Field(description: 'DAY-003')]
        public readonly ?Pill $adjust_badge,
        public readonly ?PeriWorkout $peri_workout,
        public readonly MealCheckIn $check_in,
        /** @var list<MealHint> */
        #[Field(items: MealHint::class)]
        public readonly array $hints,
        /** @var list<MealEntry> */
        #[Field(items: MealEntry::class)]
        public readonly array $entries,
        public readonly ?MealEmpty $empty,
        /** @var list<MenuItem> */
        #[Field(items: MenuItem::class)]
        public readonly array $menu,
    ) {}
}
