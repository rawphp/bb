<?php

namespace App\Capabilities\Reads\Data\Today;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A workout card (SCH-003, SCH-046, SCH-053). */
final class TodayWorkout extends CapabilityData
{
    /**
     * @param  list<WorkoutExercise>  $exercises
     * @param  list<WorkoutMenuItem>  $menu
     * @param  list<MoveDayOption>  $move_days
     */
    public function __construct(
        #[Field(description: 'The timeline entry\'s id; a day holds one session (SCH-038).')] public readonly string $id,
        public readonly string $mesocycle_id,
        public readonly int $week,
        public readonly int $day,
        #[Field(description: '"Week 3 · Day 2 · Upper A"')] public readonly string $title,
        #[Field(description: '"Upper A", the condensed card\'s title.')] public readonly string $name,
        #[Field(description: '24-hour "HH:MM", what the time sheet opens with.')] public readonly string $time,
        #[Field(description: '"5:30 PM"')] public readonly string $time_text,
        #[Field(description: '"5:30 PM · 55 min"')] public readonly string $condensed_time_text,
        #[Field(description: '"55 min · 5 exercises · 18 sets"')] public readonly string $summary_text,
        #[Field(items: WorkoutExercise::class)] public readonly array $exercises,
        #[Field(enum: ['planned', 'started', 'done', 'skipped'])] public readonly string $state,
        #[Field(description: '"Done" or "Skipped"; null while Start shows.')] public readonly ?string $status_text,
        #[Field(description: '"16 of 18 sets completed" once done.')] public readonly ?Status $completed,
        #[Field(items: WorkoutMenuItem::class)] public readonly array $menu,
        #[Field(items: MoveDayOption::class)] public readonly array $move_days,
    ) {}
}
