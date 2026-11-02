<?php

namespace App\Ai;

use App\Domain\Model\DietFilter;

/** RCP-010: what the user asks a generated recipe to be. Macros are per serving, in grams. */
final readonly class RecipeBrief
{
    /**
     * @param  list<string>  $ingredients  to use where they fit
     * @param  list<DietFilter>  $diets  every ingredient must pass them
     */
    public function __construct(
        public float $protein,
        public float $fat,
        public float $carbs,
        public ?float $kcal,
        public string $mealType,
        public string $complexity,
        public array $ingredients = [],
        public ?string $preferences = null,
        public array $diets = [],
    ) {}
}
