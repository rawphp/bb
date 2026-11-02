<?php

namespace App\Support\Today;

use App\Domain\Model\DayType;
use App\Domain\Model\MealTarget;
use DateTimeImmutable;

/** A day's stored target, its meals in stored order and the food eaten off-plan (DAY-001), as values. */
final readonly class DayPlan
{
    /**
     * @param  DateTimeImmutable  $date  midnight of the day
     * @param  list<DayMeal>  $meals
     * @param  list<MealTarget>  $offPlan
     */
    public function __construct(
        public DateTimeImmutable $date,
        public DayType $type,
        public MealTarget $target,
        public array $meals,
        public array $offPlan = [],
    ) {}

    public function meal(string $id): ?DayMeal
    {
        return array_find($this->meals, fn (DayMeal $meal) => $meal->id === $id);
    }

    /** @return list<DayMeal> the meals not checked in */
    public function openMeals(): array
    {
        return array_values(array_filter($this->meals, fn (DayMeal $meal) => ! $meal->isChecked()));
    }

    /** NUT-024: a day before today is finished, and so is a day with every meal checked in. */
    public function isFinished(DateTimeImmutable $today): bool
    {
        return $this->date < $today || ($this->meals !== [] && $this->openMeals() === []);
    }
}
