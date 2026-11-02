<?php

namespace App\Ai;

/** A recipe the AI generated (RCP-010) or read from a page (RCP-003), its ingredients matched to foods. Nothing is saved. */
final readonly class AiRecipe
{
    /**
     * @param  list<string>  $steps
     * @param  list<MatchedItem>  $items  grams for the whole recipe
     */
    public function __construct(
        public string $name,
        public int $servings,
        public array $steps,
        public array $items,
    ) {}
}
