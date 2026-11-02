<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A meal card (SCH-002, SCH-008, DAY-003, DAY-004). */
final class TodayMeal extends CapabilityData
{
    /**
     * @param  list<Status>  $badges
     * @param  list<MealMenuItem>  $menu
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        #[Field(description: '24-hour "HH:MM"; null for a meal with no time.')] public readonly ?string $time,
        #[Field(description: '"12:30 PM"')] public readonly ?string $time_text,
        #[Field(description: 'The condensed card\'s line (SCH-007): "12:30 PM · 512 kcal".')] public readonly string $condensed_time_text,
        public readonly MacroLine $macros,
        #[Field(description: '"Oats 80 g · Chili 1.5 servings"; null when nothing is planned.')] public readonly ?string $planned_text,
        #[Field(description: '"Eaten: 500 kcal, 40 g protein"; null before a check-in.')] public readonly ?string $eaten_text,
        #[Field(description: 'SCH-008: "Plan this meal before you eat it".')] public readonly ?string $plan_banner,
        #[Field(description: 'DAY-003 "Adjust foods", shown on the condensed card too.', items: Status::class)] public readonly array $badges,
        public readonly MealCheckInState $check_in,
        #[Field(items: MealMenuItem::class)] public readonly array $menu,
    ) {}
}
