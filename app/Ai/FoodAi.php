<?php

namespace App\Ai;

use App\Domain\Model\DietFilter;
use App\Models\User;

/**
 * The AI food calls: read a nutrition label (FOOD-042), list a plate's ingredients from a photo
 * (FOOD-043) or a description (FOOD-044), generate a recipe (RCP-010) and read one from a web page
 * (RCP-003). Each returns a draft for the user to confirm; none saves anything.
 *
 * With no key every call throws AiUnavailable before it does anything else; a capability that
 * makes the call refuses with its message.
 */
final class FoodAi
{
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly AiJson $ai,
        private readonly FoodMatcher $matcher,
        private readonly RecipePage $page,
    ) {}

    /** FOOD-042: a draft only, nothing is saved. */
    public function label(string $image): LabelReading
    {
        $this->ai->ensureAvailable();
        $label = $this->ai->ask('label', [
            self::image($image),
            ['type' => 'text', 'text' => 'Read this nutrition label.'],
        ], self::labelSchema());

        /** @var array{label: string, grams: int|float}|null $serving */
        $serving = $label['serving'];
        $per100g = array_map(fn (int|float $value): float => round((float) $value, 2), $label['per_100g']);

        return new LabelReading(
            name: $label['name'],
            brand: $label['brand'],
            servingLabel: $serving['label'] ?? null,
            servingGrams: $serving === null ? null : round((float) $serving['grams'], 2),
            kcal: $per100g['kcal'],
            protein: $per100g['protein'],
            fat: $per100g['fat'],
            carbs: $per100g['carbs'],
            fiber: $per100g['fiber'],
        );
    }

    /**
     * FOOD-043
     *
     * @return list<MatchedItem>
     */
    public function photo(User $user, string $image): array
    {
        $this->ai->ensureAvailable();
        $result = $this->ai->ask('photo', [
            self::image($image),
            ['type' => 'text', 'text' => 'List the ingredients on this plate with estimated grams.'],
        ], self::itemsSchema());

        return $this->matcher->match($user, $result['items']);
    }

    /**
     * FOOD-044
     *
     * @return list<MatchedItem>
     */
    public function describe(User $user, string $text): array
    {
        $result = $this->ai->ask('describe', [['type' => 'text', 'text' => $text]], self::itemsSchema());

        return $this->matcher->match($user, $result['items']);
    }

    /** RCP-010: foods are matched under the brief's diet filters. */
    public function recipe(User $user, RecipeBrief $brief): AiRecipe
    {
        $macros = ['protein' => $brief->protein, 'fat' => $brief->fat, 'carbs' => $brief->carbs];
        if ($brief->kcal !== null) {
            $macros['kcal'] = $brief->kcal;
        }
        $data = array_filter([
            'target_macros_per_serving' => $macros,
            'meal_type' => $brief->mealType,
            'ingredients_to_use' => $brief->ingredients,
            'complexity' => $brief->complexity,
            'diet_filters' => array_map(fn (DietFilter $diet): string => $diet->value, $brief->diets),
            'preferences' => $brief->preferences,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        $recipe = $this->ai->ask('recipe', [
            ['type' => 'text', 'text' => (string) json_encode($data, JSON_PRETTY_PRINT)],
        ], self::recipeSchema());

        return $this->recipeFrom($user, $recipe, $brief->diets);
    }

    /**
     * RCP-003: the page is fetched without reaching internal addresses (RecipePage).
     *
     * @throws PageUnavailable when the page is refused or cannot be fetched; nothing is sent to the AI
     */
    public function recipeImport(User $user, string $url): AiRecipe
    {
        $this->ai->ensureAvailable();
        try {
            $text = $this->page->text($url);
        } catch (PageUnavailable $e) {
            throw new PageUnavailable('We couldn\'t fetch that page.', previous: $e);
        }

        $recipe = $this->ai->ask('recipe-import', [['type' => 'text', 'text' => $text]], self::recipeSchema());

        return $this->recipeFrom($user, $recipe);
    }

    /**
     * @param  array<string, mixed>  $recipe  matches recipeSchema()
     * @param  list<DietFilter>  $diets
     */
    private function recipeFrom(User $user, array $recipe, array $diets = []): AiRecipe
    {
        return new AiRecipe(
            name: $recipe['name'],
            servings: $recipe['servings'],
            steps: $recipe['instructions'],
            items: $this->matcher->match($user, $recipe['items'], $diets),
        );
    }

    /**
     * An image content block from a base64 data URL of a JPEG, PNG, WebP or GIF.
     *
     * @return array<string, mixed>
     */
    private static function image(string $image): array
    {
        if (preg_match('#^data:(image/(?:jpeg|png|webp|gif));base64,([A-Za-z0-9+/]+={0,2})$#', $image, $parts) !== 1
            || base64_decode($parts[2], true) === false) {
            throw new AiFailed('That photo couldn\'t be read. Try another.');
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $parts[1], 'data' => $parts[2]]];
    }

    /** @return array<string, mixed> */
    private static function labelSchema(): array
    {
        $grams = ['type' => 'number'];

        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'brand' => ['type' => ['string', 'null']],
                'serving' => [
                    'type' => ['object', 'null'],
                    'properties' => ['label' => ['type' => 'string'], 'grams' => $grams],
                    'required' => ['label', 'grams'],
                    'additionalProperties' => false,
                ],
                'per_100g' => [
                    'type' => 'object',
                    'properties' => ['kcal' => $grams, 'protein' => $grams, 'fat' => $grams, 'carbs' => $grams, 'fiber' => $grams],
                    'required' => ['kcal', 'protein', 'fat', 'carbs', 'fiber'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['name', 'brand', 'serving', 'per_100g'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function itemsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['items' => self::itemList()],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function recipeSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'servings' => ['type' => 'integer'],
                'instructions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'items' => self::itemList(),
            ],
            'required' => ['name', 'servings', 'instructions', 'items'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Each item is one food search, so the list is bounded: a plate or a recipe has far fewer.
     *
     * @return array<string, mixed>
     */
    private static function itemList(): array
    {
        return [
            'type' => 'array',
            'maxItems' => self::MAX_ITEMS,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'search' => ['type' => 'string'],
                    'grams' => ['type' => 'number'],
                ],
                'required' => ['name', 'search', 'grams'],
                'additionalProperties' => false,
            ],
        ];
    }
}
