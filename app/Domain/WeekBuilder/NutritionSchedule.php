<?php

namespace App\Domain\WeekBuilder;

use App\Domain\MealSplit\CarbPlacement;
use App\Domain\Model\StepPlanDay;
use App\Domain\Placement\ClockRange;

/** ONB-013, ONB-014: the nutrition half of the schedule. Null on WeekSchedule means training-only (SAFE-003). */
final readonly class NutritionSchedule
{
    /**
     * @param  list<StepPlanDay>  $steps  The planned steps by ISO weekday (1 = Monday). A weekday with no entry uses the
     *                                    lowest activity factor (0 steps).
     * @param  list<ClockRange>  $busy
     * @param  array<int, list<ClockRange>>|null  $busyByWeekday  Busy periods by ISO weekday (1 = Monday ... 7 = Sunday).
     *                                                            Null uses $busy on every day; when set, a day takes its
     *                                                            own list and a weekday with no entry has no busy periods.
     */
    public function __construct(
        public array $steps,
        public ClockRange $eatingWindow,
        public int $mealsPerDay,
        public array $busy = [],
        public CarbPlacement $carbPlacement = CarbPlacement::TrainingCentred,
        public ?array $busyByWeekday = null,
    ) {}

    /**
     * The busy periods on an ISO weekday.
     *
     * @return list<ClockRange>
     */
    public function busyOnWeekday(int $weekday): array
    {
        return $this->busyByWeekday === null ? $this->busy : $this->busyByWeekday[$weekday] ?? [];
    }
}
