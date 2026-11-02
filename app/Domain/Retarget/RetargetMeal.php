<?php

namespace App\Domain\Retarget;

use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;
use App\Domain\WeekBuilder\WeekMealKind;

/** A meal on a day being re-targeted, with what the user has done to it (SCH-044, SCH-055). */
final readonly class RetargetMeal
{
    /**
     * @param  bool  $hasHandEditedTarget  NUT-035.
     * @param  bool  $hasHandSetTime  SCH-044.
     */
    public function __construct(
        public Meal $meal,
        public ClockTime $time,
        public WeekMealKind $kind,
        public bool $isCheckedIn = false,
        public bool $isLocked = false,
        public bool $hasHandEditedTarget = false,
        public bool $hasHandSetTime = false,
    ) {}

    public function id(): string
    {
        return $this->meal->id;
    }

    /** SCH-055: a re-target never changes this meal, its time or its target. */
    public function isFixed(): bool
    {
        return $this->isCheckedIn || $this->isLocked || $this->hasHandEditedTarget;
    }

    /** SCH-044, SCH-055: a re-target never moves this meal. */
    public function keepsTime(): bool
    {
        return $this->isFixed() || $this->hasHandSetTime;
    }

    public function atTime(ClockTime $time): self
    {
        return new self($this->meal, $time, $this->kind, $this->isCheckedIn, $this->isLocked, $this->hasHandEditedTarget, $this->hasHandSetTime);
    }

    public function ofKind(WeekMealKind $kind): self
    {
        return new self($this->meal, $this->time, $kind, $this->isCheckedIn, $this->isLocked, $this->hasHandEditedTarget, $this->hasHandSetTime);
    }

    public function withTarget(MealTarget $target): self
    {
        return new self(
            new Meal($this->meal->id, $this->meal->name, $target), $this->time, $this->kind,
            $this->isCheckedIn, $this->isLocked, $this->hasHandEditedTarget, $this->hasHandSetTime,
        );
    }
}
