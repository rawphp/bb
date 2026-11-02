<?php

namespace App\Domain\Scan;

use App\Domain\Macros\MacroRules;

/**
 * FOOD-042: the custom food form's starting point from a nutrition label read per 100 g. The serving is the
 * label's, or 100 g when it names none or one weighing nothing; the nutrition is for that serving, to one
 * decimal. Its calories are the ones the saved custom food will show: computed from those macros (FOOD-060),
 * to a whole number, not the label's printed figure.
 */
final readonly class LabelDraft
{
    public const DEFAULT_SERVING_GRAMS = 100.0;

    private function __construct(
        public float $servingGrams,
        public float $kcal,
        public float $protein,
        public float $fat,
        public float $carbs,
        public float $fiber,
    ) {}

    /** Every value but the serving is per 100 g, as read from the label. */
    public static function perServing(?float $servingGrams, float $protein, float $fat, float $carbs, float $fiber): self
    {
        $grams = $servingGrams !== null && $servingGrams > 0 ? $servingGrams : self::DEFAULT_SERVING_GRAMS;
        $serving = fn (float $per100g): float => round($per100g * $grams / 100, 1);
        [$protein, $fat, $carbs] = [$serving($protein), $serving($fat), $serving($carbs)];

        return new self(
            servingGrams: $grams,
            kcal: round(MacroRules::kcal($protein, $carbs, $fat)),
            protein: $protein,
            fat: $fat,
            carbs: $carbs,
            fiber: $serving($fiber),
        );
    }
}
