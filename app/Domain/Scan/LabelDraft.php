<?php

namespace App\Domain\Scan;

/**
 * FOOD-042: the custom food form's starting point from a nutrition label read per 100 g. The serving is the
 * label's, or 100 g when it names none or one weighing nothing; the nutrition is for that serving, to one
 * decimal, and its calories to a whole number.
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
    public static function perServing(?float $servingGrams, float $kcal, float $protein, float $fat, float $carbs, float $fiber): self
    {
        $grams = $servingGrams !== null && $servingGrams > 0 ? $servingGrams : self::DEFAULT_SERVING_GRAMS;
        $serving = fn (float $per100g): float => $per100g * $grams / 100;

        return new self(
            servingGrams: $grams,
            kcal: round($serving($kcal)),
            protein: round($serving($protein), 1),
            fat: round($serving($fat), 1),
            carbs: round($serving($carbs), 1),
            fiber: round($serving($fiber), 1),
        );
    }
}
