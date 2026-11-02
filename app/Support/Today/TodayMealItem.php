<?php

namespace App\Support\Today;

use App\Domain\MealFit\MealFit;
use App\Domain\Model\MealCheckInState;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;

/** A meal of a day (SCH-002): its targets joined with what was planned and eaten. */
final readonly class TodayMealItem
{
    public const STILL_PLANNING = 'still_planning';

    /**
     * @param  list<TodayPlannedEntry>  $foods
     * @param  list<TodayPlannedEntry>  $recipes
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?ClockTime $time,
        public MealTarget $target,
        public array $foods,
        public array $recipes,
        public ?MealTarget $eaten,
        public ?string $eatenTime,
        public ?string $planningState,
    ) {}

    public function isChecked(): bool
    {
        return $this->eaten !== null;
    }

    public function hasEntries(): bool
    {
        return $this->foods !== [] || $this->recipes !== [];
    }

    /** @return list<TodayPlannedEntry> the planned foods and recipe servings in their planned order */
    public function entries(): array
    {
        $entries = [...$this->foods, ...$this->recipes];
        usort($entries, fn (TodayPlannedEntry $a, TodayPlannedEntry $b) => $a->position <=> $b->position);

        return $entries;
    }

    /** What the planned foods and recipe servings add up to. */
    public function planned(): MealTarget
    {
        $kcal = $protein = $fat = $carbs = 0.0;
        foreach ([...$this->foods, ...$this->recipes] as $entry) {
            $kcal += $entry->macros->kcal;
            $protein += $entry->macros->proteinG;
            $fat += $entry->macros->fatG;
            $carbs += $entry->macros->carbsG;
        }

        return new MealTarget(kcal: $kcal, proteinG: $protein, fatG: $fat, carbsG: $carbs);
    }

    /** MEAL-040, MEAL-042: what a one-tap check-in records: the planned entries, or the targets with nothing planned. */
    public function plannedCheckIn(): MealTarget
    {
        return $this->hasEntries() ? $this->planned() : $this->target;
    }

    /** SCH-008: an empty meal that is not eaten yet. */
    public function showsPlanBanner(): bool
    {
        return ! $this->hasEntries() && $this->eaten === null;
    }

    /** DAY-003: a meal still being planned whose planned entries miss its targets (MEAL-003). */
    public function showsAdjustBadge(): bool
    {
        return $this->planningState === self::STILL_PLANNING && $this->eaten === null && $this->hasEntries()
            && ! (new MealFit)->targetsMet($this->planned(), $this->target);
    }

    /** MEAL-043, MEAL-047. */
    public function checkInState(): MealCheckInState
    {
        return MealCheckInState::of($this->eaten, $this->target, new MealFit);
    }
}
