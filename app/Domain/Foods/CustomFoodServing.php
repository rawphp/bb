<?php

namespace App\Domain\Foods;

use App\Domain\Macros\MacroRules;

/**
 * FOOD-060: one serving of a custom food, exactly as the user entered it. A food row holds its
 * nutrition per 100 g, so the serving is kept as entered for the edit form, and the row's
 * values are worked out from it here. Calories are never entered: they come from the macros.
 */
final readonly class CustomFoodServing
{
    public function __construct(
        public float $size,
        public string $unit,
        /** "Weight per serving", in grams. */
        public float $grams,
        public float $protein,
        public float $fat,
        public float $carbs,
        public float $fiber,
    ) {}

    /** @param  array<mixed>  $serving  a stored serving: {@see toArray()}, its whole numbers maybe integers */
    public static function fromArray(array $serving): self
    {
        return new self(
            (float) $serving['size'], (string) $serving['unit'], (float) $serving['grams'],
            (float) $serving['protein'], (float) $serving['fat'], (float) $serving['carbs'], (float) $serving['fiber'],
        );
    }

    /** @return array{size: float, unit: string, grams: float, protein: float, fat: float, carbs: float, fiber: float} */
    public function toArray(): array
    {
        return [
            'size' => $this->size, 'unit' => $this->unit, 'grams' => $this->grams,
            'protein' => $this->protein, 'fat' => $this->fat, 'carbs' => $this->carbs, 'fiber' => $this->fiber,
        ];
    }

    /** The serving's calories (NUT-018); fiber is not counted. */
    public function kcal(): float
    {
        return MacroRules::kcal($this->protein, $this->carbs, $this->fat);
    }

    /**
     * The food row's values: per 100 g, rounded to 2 places, with calories from the rounded macros.
     *
     * @return array{kcal: float, protein: float, fat: float, carbs: float, fiber: float}
     */
    public function per100g(): array
    {
        $per100 = fn (float $grams): float => round($grams * 100 / $this->grams, 2);
        $protein = $per100($this->protein);
        $fat = $per100($this->fat);
        $carbs = $per100($this->carbs);

        return [
            'kcal' => round(MacroRules::kcal($protein, $carbs, $fat), 2),
            'protein' => $protein,
            'fat' => $fat,
            'carbs' => $carbs,
            'fiber' => $per100($this->fiber),
        ];
    }

    /** "1 cup", "1.5 tbsp": the size without trailing zeros, then the unit. */
    public function label(): string
    {
        return self::number($this->size).' '.$this->unit;
    }

    /** FOOD-061: a row's second line, "1.5 tbsp · 100 kcal". */
    public function detail(): string
    {
        return $this->label().' · '.(int) round($this->kcal()).' kcal';
    }

    /** 1.5 as "1.5", 30 as "30", 33.333 as "33.333": every entered digit, no trailing zeros. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
