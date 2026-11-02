<?php

namespace App\Ai;

/** FOOD-042: a nutrition label read by the AI, per 100 g and rounded to 2 places, for the custom food form. Nothing is saved. */
final readonly class LabelReading
{
    public function __construct(
        public string $name,
        public ?string $brand,
        /** As printed, such as "1 cup" or "3 biscuits"; null when the label gives no serving weight */
        public ?string $servingLabel,
        public ?float $servingGrams,
        public float $kcal,
        public float $protein,
        public float $fat,
        public float $carbs,
        public float $fiber,
    ) {}
}
