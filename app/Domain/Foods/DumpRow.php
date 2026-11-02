<?php

namespace App\Domain\Foods;

/**
 * Turns one record of a downloaded food dump into a foods row and its servings (FOOD-001, FOOD-004, FOOD-008),
 * or null when the record cannot be a food.
 *
 * USDA FoodData Central: nutrients are per 100 g by nutrient.number: 208 kcal (957/958 Atwater when
 * missing), 203 protein, 204 fat, 205 carbs, 291 fiber. Foundation/SR foods are kind generic; Branded
 * foods are packaged, or restaurant when the branded category contains "restaurant".
 * Open Food Facts: energy is energy-kcal_100g, else energy_100g (kJ) / 4.184. Products are packaged,
 * or restaurant when a category tag names a restaurant or fast food.
 *
 * Records without a name or energy are skipped, as are records with impossible values (kcal over 1000,
 * a macro over 100 g, a negative or non-finite number). Servings outside 0 < grams <= 5000 are dropped
 * and codes longer than 14 digits get no barcode, but the food is still kept. Diet flags: DietClassifier.
 *
 * @phpstan-type Serving array{label: string, grams: float}
 * @phpstan-type Row array{food: array<string, string|float|bool|null>, servings: list<Serving>}
 */
final class DumpRow
{
    /** Heaviest serving kept, in grams; anything above is a data error. */
    private const MAX_SERVING_GRAMS = 5000;

    public function __construct(private readonly DietClassifier $diets = new DietClassifier) {}

    /**
     * One food from a FoundationFoods, SRLegacyFoods or BrandedFoods array.
     *
     * @param  array<string, mixed>  $item
     * @return Row|null
     */
    public function fromUsda(array $item, bool $branded): ?array
    {
        $name = $this->clean($item['description'] ?? null, 255);
        $nutrients = [];
        foreach ($this->list($item['foodNutrients'] ?? null) as $n) {
            $number = $n['nutrient']['number'] ?? null;
            $amount = $n['amount'] ?? null;
            // Keyed by the exact number: 205.2 (carbs by summation) must not overwrite 205 (by difference).
            if (is_string($number) && ctype_digit($number) && is_numeric($amount)) {
                $nutrients[$number] = (float) $amount;
            }
        }
        $kcal = $nutrients['208'] ?? $nutrients['957'] ?? $nutrients['958'] ?? null;
        $sourceId = $this->text($item['fdcId'] ?? null);

        if ($name === null || $kcal === null || $sourceId === '') {
            return null;
        }

        if ($branded) {
            $category = $this->text($item['brandedFoodCategory'] ?? null);
            $food = [
                'brand' => $this->clean($item['brandName'] ?? null, 255) ?? $this->clean($item['brandOwner'] ?? null, 255),
                'barcode' => Barcode::normalize($this->text($item['gtinUpc'] ?? null)),
                'kind' => stripos($category, 'restaurant') !== false ? 'restaurant' : 'packaged',
                'diets' => $this->diets->classify($name, $category, $this->clean($item['ingredients'] ?? null, 65535)),
                'servings' => $this->brandedServing($item),
            ];
        } else {
            $category = $this->text($item['foodCategory']['description'] ?? null);
            $food = [
                'brand' => null,
                'barcode' => null,
                'kind' => 'generic',
                'diets' => $this->diets->classify($name, $category, null, [], generic: true),
                'servings' => $this->portions($this->list($item['foodPortions'] ?? null)),
            ];
        }

        return $this->build(FoodSource::Usda, $sourceId, $name, $food, $kcal, [
            $nutrients['203'] ?? 0.0, $nutrients['204'] ?? 0.0, $nutrients['205'] ?? 0.0, $nutrients['291'] ?? 0.0,
        ]);
    }

    /**
     * One product from the Open Food Facts JSONL export.
     *
     * @param  array<string, mixed>  $p
     * @return Row|null
     */
    public function fromOpenFoodFacts(array $p): ?array
    {
        $code = trim($this->text($p['code'] ?? null));
        $name = $this->clean($p['product_name'] ?? null, 255) ?? $this->clean($p['product_name_en'] ?? null, 255);
        $n = is_array($p['nutriments'] ?? null) ? $p['nutriments'] : [];

        $kcal = is_numeric($n['energy-kcal_100g'] ?? null) ? (float) $n['energy-kcal_100g']
            : (is_numeric($n['energy_100g'] ?? null) ? (float) $n['energy_100g'] / 4.184 : null);

        if ($code === '' || strlen($code) > 64 || $name === null || $kcal === null) {
            return null;
        }

        $categories = $this->strings($p['categories_tags'] ?? null);
        $restaurant = array_filter($categories, fn (string $c) => str_contains($c, 'restaurant') || str_contains($c, 'fast-food')) !== [];
        // Comma-joined, so the classifier can drop one category (a substitute) and keep the rest.
        $categoryText = str_replace(['en:', '-'], ['', ' '], implode(', ', $categories));
        $tags = [...$this->strings($p['ingredients_analysis_tags'] ?? null), ...$this->strings($p['allergens_tags'] ?? null)];
        // The keyword rules are English: another language's list matches none of them and would set every flag.
        $ingredients = $this->clean($p['ingredients_text_en'] ?? null, 65535)
            ?? ($this->text($p['lang'] ?? null) === 'en' ? $this->clean($p['ingredients_text'] ?? null, 65535) : null);

        $serving = [];
        $label = $this->clean($p['serving_size'] ?? null, 255);
        $grams = is_numeric($p['serving_quantity'] ?? null) ? $this->servingGrams((float) $p['serving_quantity']) : null;
        if ($grams !== null && $label !== null) {
            $serving = [['label' => $label, 'grams' => $grams]];
        }

        $food = [
            'brand' => $this->clean(explode(',', $this->text($p['brands'] ?? null))[0], 255),
            'barcode' => Barcode::normalize($code),
            'kind' => $restaurant ? 'restaurant' : 'packaged',
            'diets' => $this->diets->classify($name, $categoryText, $ingredients, $tags),
            'servings' => $serving,
        ];
        $macro = fn (string $key) => is_numeric($n[$key] ?? null) ? (float) $n[$key] : 0.0;

        return $this->build(FoodSource::OpenFoodFacts, $code, $name, $food, $kcal, [
            $macro('proteins_100g'), $macro('fat_100g'), $macro('carbohydrates_100g'), $macro('fiber_100g'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $portions
     * @return list<Serving>
     */
    private function portions(array $portions): array
    {
        $out = [];
        foreach ($portions as $p) {
            $grams = is_numeric($p['gramWeight'] ?? null) ? (float) $p['gramWeight'] : 0.0;
            $amount = is_numeric($p['amount'] ?? null) ? (float) $p['amount'] : 1.0;
            $unit = trim($this->text($p['measureUnit']['name'] ?? null));
            $unit = $unit === 'undetermined' ? '' : $unit;
            $extra = trim($this->text($p['modifier'] ?? null));
            if ($unit === '' && $extra === '') {
                $extra = trim($this->text($p['portionDescription'] ?? null));
            }
            $label = $this->clean(trim($unit.' '.$extra), 255);
            $perUnit = $this->servingGrams($grams / ($amount > 0 ? $amount : 1));
            if ($label === null || $perUnit === null) {
                continue;
            }
            $out[$label] = ['label' => $label, 'grams' => $perUnit];
        }

        return array_values($out);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<Serving>
     */
    private function brandedServing(array $item): array
    {
        $size = is_numeric($item['servingSize'] ?? null) ? $this->servingGrams((float) $item['servingSize']) : null;
        $unit = strtolower($this->text($item['servingSizeUnit'] ?? null));
        if ($size === null || ! in_array($unit, ['g', 'grm', 'ml', 'mlt'], true)) {
            return [];
        }
        // A millilitre is counted as a gram: USDA gives no density, and most branded drinks are close to water.
        $label = $this->clean($item['householdServingFullText'] ?? null, 255) ?? 'serving';

        return [['label' => $label, 'grams' => $size]];
    }

    /**
     * @param  array{brand: ?string, barcode: ?string, kind: string, diets: array<string, bool>, servings: list<Serving>}  $food
     * @param  array{float, float, float, float}  $macros  protein, fat, carbs, fiber
     * @return Row|null
     */
    private function build(FoodSource $source, string $sourceId, string $name, array $food, float $kcal, array $macros): ?array
    {
        if (! is_finite($kcal) || array_filter($macros, fn (float $m) => ! is_finite($m)) !== []
            || $kcal < 0 || $kcal > 1000 || min($macros) < 0 || max($macros) > 100) {
            return null;
        }
        [$protein, $fat, $carbs, $fiber] = $macros;

        return [
            'food' => [
                'name' => $name,
                'brand' => $food['brand'],
                'barcode' => $food['barcode'],
                'source' => $source->value,
                'source_id' => $sourceId,
                'kind' => $food['kind'],
                'kcal' => round($kcal, 2),
                'protein' => round($protein, 2),
                'fat' => round($fat, 2),
                'carbs' => round($carbs, 2),
                'fiber' => round($fiber, 2),
            ] + $food['diets'],
            'servings' => $food['servings'],
        ];
    }

    /** Grams rounded to 2 places, or null when not a finite weight above 0 and up to MAX_SERVING_GRAMS. */
    private function servingGrams(float $grams): ?float
    {
        return is_finite($grams) && $grams > 0 && $grams <= self::MAX_SERVING_GRAMS ? round($grams, 2) : null;
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim($this->text($value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }
}
