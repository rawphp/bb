<?php

namespace App\Domain\Onboarding;

/** A week of daily calories (ONB-017): each day in plan-week order from the review day, and the daily average. */
final readonly class CalorieWeek
{
    public float $averageKcal;

    /** @param  list<CalorieDay>  $days */
    public function __construct(public array $days)
    {
        $this->averageKcal = $days === [] ? 0.0 : array_sum(array_map(fn (CalorieDay $day) => $day->kcal, $days)) / count($days);
    }
}
