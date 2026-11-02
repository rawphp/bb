<?php

namespace App\Domain\Macros;

use App\Domain\Model\DayType;
use App\Domain\Model\Profile;
use App\Domain\Model\UnitConversion;

/** A week of daily targets. The days add up to the weekly goal (NUT-020). */
final readonly class WeekTargets
{
    /** @param  list<DayTarget>  $days */
    public function __construct(
        public array $days,
        public float $leanMassKg,
        public int $mealsPerDay,
    ) {}

    public function weeklyKcal(): float
    {
        return array_sum(array_map(fn (DayTarget $day) => $day->kcal(), $this->days));
    }

    public function dailyAverageKcal(): float
    {
        return $this->days === [] ? 0 : $this->weeklyKcal() / count($this->days);
    }

    /**
     * NUT-015, NUT-016: change every day by the same calories (a weekly-review adjustment).
     * Protein never changes (SAFE-008).
     *
     * Cuts come out of fat first, down to the fat floor for the new calories, then out of carbs as the same
     * grams on every day. Increases go to carbs until training-day carbs reach 3 g per lb of lean mass, then
     * split 25% fat / 75% carbs (NUT-016 gives no ratio; this is the NUT-012 default fat share).
     *
     * @throws TargetRefusal
     */
    public function adjusted(float $byKcalPerDay, Profile $profile): self
    {
        if ($byKcalPerDay === 0.0 || $this->days === []) {
            return $this;
        }
        if ($byKcalPerDay < 0) {
            $bmi = MacroTargets::bmi($profile);
            if ($bmi !== null && $bmi <= MacroRules::BMI_CUT_FLOOR) {
                throw TargetRefusal::bmiAtOrBelowFloor($bmi); // SAFE-011
            }

            return $this->applyingCut(-$byKcalPerDay);
        }

        return $this->applyingIncrease($byKcalPerDay);
    }

    /**
     * NUT-021, NUT-022, NUT-024: move calories from one day to another through carbs, so the weekly total and
     * every day's protein and fat hold.
     *
     * @throws MoveRefusal
     */
    public function moving(float $kcal, int $from, int $to): self
    {
        if ($kcal <= 0 || $from === $to || ! isset($this->days[$from]) || ! isset($this->days[$to])) {
            throw MoveRefusal::invalid();
        }
        if ($this->days[$from]->isCompleted) {
            throw MoveRefusal::dayCompleted($from);
        }
        if ($this->days[$to]->isCompleted) {
            throw MoveRefusal::dayCompleted($to);
        }

        $carbsShift = $kcal / MacroRules::KCAL_PER_GRAM_CARBS;
        $average = $this->dailyAverageKcal();
        $tolerance = 1e-6;
        $band = MacroRules::moveBand();
        $low = $band->lower * $average - $tolerance;
        $high = $band->upper * $average + $tolerance;
        $minimumMealG = MacroRules::MEAL_MINIMUM_G * $this->mealsPerDay;

        $result = $this->days;
        foreach ([$from => $this->days[$from]->carbsG - $carbsShift, $to => $this->days[$to]->carbsG + $carbsShift] as $index => $carbs) {
            $moved = $this->replacing($this->days[$index], carbsG: $carbs);
            if ($moved->kcal() < $low || $moved->kcal() > $high) {
                throw MoveRefusal::outsideBand($index);
            }
            if ($moved->fatG < MacroRules::fatShareFloorG($moved->kcal()) - $tolerance
                || $carbs < $minimumMealG) {
                throw MoveRefusal::breaksFloor($index); // SAFE-009, SAFE-010
            }
            $result[$index] = $moved;
        }

        return new self($result, $this->leanMassKg, $this->mealsPerDay);
    }

    /**
     * NUT-015: how many grams of fat a cut of $cut kcal a day takes. Fat goes first, down to the fat floor for the
     * new calories; less than $cut / 9 means the cut reaches the fat floor.
     */
    public function fatCutG(float $cut): float
    {
        $newFloor = MacroRules::fatFloorG($this->maxDayKcal() - $cut, $this->leanMassKg, $this->mealsPerDay);

        return min($cut / MacroRules::KCAL_PER_GRAM_FAT, max(0, $this->days[0]->fatG - $newFloor));
    }

    /** @throws TargetRefusal */
    private function applyingCut(float $cut): self
    {
        $fatCutG = $this->fatCutG($cut);
        $carbCutG = ($cut - MacroRules::KCAL_PER_GRAM_FAT * $fatCutG) / MacroRules::KCAL_PER_GRAM_CARBS;
        $minimumMealG = MacroRules::MEAL_MINIMUM_G * $this->mealsPerDay;

        $result = [];
        foreach ($this->days as $index => $day) {
            $carbs = $day->carbsG - $carbCutG;
            if ($carbs < $minimumMealG) {
                throw TargetRefusal::breaksFloor($index); // SAFE-012
            }
            $result[] = $this->replacing($day, carbsG: $carbs, fatG: $day->fatG - $fatCutG);
        }

        return new self($result, $this->leanMassKg, $this->mealsPerDay);
    }

    private function applyingIncrease(float $extra): self
    {
        $capG = MacroRules::INCREASE_CARB_CAP_G_PER_LB_LEAN * UnitConversion::kgToLb($this->leanMassKg);
        $trainingCarbs = array_map(
            fn (DayTarget $day) => $day->carbsG,
            array_filter($this->days, fn (DayTarget $day) => $day->type !== DayType::Rest),
        );
        $headroomG = $trainingCarbs === [] ? INF : max(0, $capG - max($trainingCarbs));
        $carbsFirstG = min($extra / MacroRules::KCAL_PER_GRAM_CARBS, $headroomG);
        $remainder = $extra - MacroRules::KCAL_PER_GRAM_CARBS * $carbsFirstG;
        $fatAddG = $remainder * MacroRules::INCREASE_FAT_SHARE_AFTER_CAP / MacroRules::KCAL_PER_GRAM_FAT;
        $carbsAddG = $carbsFirstG
            + $remainder * (1 - MacroRules::INCREASE_FAT_SHARE_AFTER_CAP) / MacroRules::KCAL_PER_GRAM_CARBS;
        // SAFE-009: the biggest day grew, so fat may now sit under 15% of it. Raise fat to the floor and take
        // those calories back out of carbs, so every day still gains exactly $extra.
        $requiredFatG = MacroRules::fatShareFloorG($this->maxDayKcal() + $extra);

        $result = array_map(function (DayTarget $day) use ($fatAddG, $carbsAddG, $requiredFatG) {
            $fat = $day->fatG + $fatAddG;
            $shiftG = max(0, $requiredFatG - $fat);

            return $this->replacing(
                $day,
                carbsG: $day->carbsG + $carbsAddG - $shiftG * MacroRules::KCAL_PER_GRAM_FAT / MacroRules::KCAL_PER_GRAM_CARBS,
                fatG: $fat + $shiftG,
            );
        }, $this->days);

        return new self($result, $this->leanMassKg, $this->mealsPerDay);
    }

    private function maxDayKcal(): float
    {
        return max(array_map(fn (DayTarget $day) => $day->kcal(), $this->days));
    }

    /** The day with new fat or carbs; protein is never touched and the TBL-032 flag is recomputed. */
    private function replacing(DayTarget $day, float $carbsG, ?float $fatG = null): DayTarget
    {
        return new DayTarget(
            $day->type, $day->isCompleted, proteinG: $day->proteinG, fatG: $fatG ?? $day->fatG, carbsG: $carbsG,
            carbBoundsWarning: MacroTargets::carbWarning($day->type, $carbsG, $this->leanMassKg),
        );
    }
}
