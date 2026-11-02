<?php

namespace App\Domain\WeekBuilder;

use App\Domain\Placement\TrainingDay;

/** The user's schedule settings a week is built from. */
final readonly class WeekSchedule
{
    /**
     * @param  list<TrainingDay>  $trainingDays  ONB-011.
     * @param  ?NutritionSchedule  $nutrition  Null: training-only mode (SAFE-003).
     */
    public function __construct(
        public array $trainingDays,
        public ?NutritionSchedule $nutrition,
    ) {}
}
