<?php

namespace App\Domain\Retarget;

use App\Domain\Model\Day;
use App\Domain\Model\DayType;
use App\Domain\Model\MealTarget;
use App\Domain\WeekBuilder\WeekWorkout;

/** One day as the re-target rules see it. */
final readonly class RetargetDay
{
    /**
     * @param  Day  $day  The start of the day, and SCH-038: `rest`, or the highest intensity of the day's workouts.
     * @param  ?WeekWorkout  $session  SCH-038: at most one mesocycle session.
     * @param  list<RetargetMeal>  $meals  In time order, the intra-workout shake among them.
     * @param  MealTarget  $target  The day's totals.
     */
    public function __construct(
        public Day $day,
        public ?WeekWorkout $session,
        public ?OtherActivity $otherActivity,
        public array $meals,
        public MealTarget $target,
    ) {}

    public function withSession(?WeekWorkout $session): self
    {
        return new self($this->day, $session, $this->otherActivity, $this->meals, $this->target);
    }

    public function withOtherActivity(?OtherActivity $activity): self
    {
        return new self($this->day, $this->session, $activity, $this->meals, $this->target);
    }

    public function withDayType(DayType $type): self
    {
        return new self(new Day($this->day->date, $type), $this->session, $this->otherActivity, $this->meals, $this->target);
    }

    public function withTarget(MealTarget $target): self
    {
        return new self($this->day, $this->session, $this->otherActivity, $this->meals, $target);
    }

    /** @param  list<RetargetMeal>  $meals */
    public function withMeals(array $meals): self
    {
        return new self($this->day, $this->session, $this->otherActivity, $meals, $this->target);
    }
}
