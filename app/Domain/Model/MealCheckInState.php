<?php

namespace App\Domain\Model;

/** MEAL-043, MEAL-047: a meal's check-in against its targets, as the card and the meal screen show it. */
enum MealCheckInState: string
{
    case NotCheckedIn = 'notCheckedIn';
    /** MEAL-043: protein, fat and carbs eaten are each within tolerance of target. */
    case OnTrack = 'onTrack';
    /** Off track, with fewer calories eaten than the target. */
    case Under = 'under';
    /** Off track, with the target's calories or more. */
    case Over = 'over';

    /** Calories decide between under and over, whatever the macros did on their own. */
    public static function of(?MealTarget $eaten, MealTarget $target, MealFitRules $mealFit): self
    {
        if ($eaten === null) {
            return self::NotCheckedIn;
        }

        if ($mealFit->targetsMet($eaten, $target)) {
            return self::OnTrack;
        }

        return $eaten->kcal < $target->kcal ? self::Under : self::Over;
    }

    public function isCheckedIn(): bool
    {
        return $this !== self::NotCheckedIn;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotCheckedIn => 'Not checked in',
            self::OnTrack => 'Checked in, on track',
            self::Under => 'Checked in, under target',
            self::Over => 'Checked in, over target',
        };
    }
}
