<?php

namespace App\Domain\Defaults\TBL033CarbsPerMeal;

final readonly class Row
{
    /**
     * @param  ?float  $bedtimePercent  null where the table says n/a
     * @param  ?int  $restMeals  the number of meals the rest share is split over
     */
    public function __construct(
        public int $meals,
        public float $preWorkoutPercent,
        public float $postWorkoutPercent,
        public ?float $bedtimePercent,
        public ?float $restPercent,
        public ?int $restMeals,
    ) {}
}
