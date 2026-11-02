<?php

namespace App\Domain\MealSplit;

use App\Domain\Model\MealTarget;

/** One item in the day's ordered list. Workouts only give the split a position to work from. */
final readonly class DayEntry
{
    private function __construct(
        public DayEntryKind $kind,
        public ?string $id = null,
        public ?MealTarget $fixed = null,
    ) {}

    /** $fixed is a hand-edited (NUT-035) or checked-in target that must not change. */
    public static function meal(string $id, ?MealTarget $fixed = null): self
    {
        return new self(DayEntryKind::Meal, $id, $fixed);
    }

    public static function workout(): self
    {
        return new self(DayEntryKind::Workout);
    }

    /** SCH-043: an intra-workout shake, tied to the workout. */
    public static function intraWorkoutShake(string $id): self
    {
        return new self(DayEntryKind::IntraWorkoutShake, $id);
    }
}
